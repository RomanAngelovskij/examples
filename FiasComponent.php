<?php

namespace app\components\fias;

use app\models\exchange\dictionary\jobs\import\DictionaryViewJob;
use app\models\exchange\dictionary\jobs\import\FinishJob;
use app\models\exchange\dictionary\jobs\import\ItemDeleteUnsyncedJob;
use app\models\exchange\dictionary\jobs\import\ItemUpdateJob;
use app\models\exchange\dictionary\jobs\import\StartJob;
use app\models\HistoryChain;
use Yii;
use yii\base\Component;
use yii\console\Exception;

class FiasComponent extends Component
{

    const DICTIONARY_SN = 'location_road_fias';

    /**
     * Идет импорт
     */
    const STATE_IMPORT_PROGRESS = 'in_progress';

    /**
     * Импорт завершен с ошибкой
     */
    const STATE_FINISH_ERROR = 'failed';

    /**
     * Импорт успешно завершен
     */
    const STATE_FINISH_SUCCESS = 'success';

    /**
     * @var string Папка с логами и XML файлами
     */
    public $fiasFolderPath = '@app/fias';

    /**
     * @var string Папка для загрузки XML
     */
    public $fiasXmlFolderPath = '@app/fias/xml';

    /**
     * @var string Папка для хранения логов
     */
    public $fiasLogFolderPath = '@app/fias/log';

    /**
     * @var string URL для получения информации об актуальном состоянии базы через SOAP
     */
    public $fiasWsdl = 'http://fias.nalog.ru/WebServices/Public/DownloadService.asmx?WSDL';

    /**
     * @var int Версия первой базы по нумерации ФИАС
     */
    public $firstVersionNum = 365;

    /**
     * @var string Лог для служебной информации
     */
    public $fiasLogFile = 'fias.log';

    /**
     * @var array Конфиги с описание XML файлов. Ключ - префикс xml файла, после которого идет информация о версии,
     *              значение - массив с описание как обрабатывать фаил:
     *              type - тип обрабатываемых объектов (город, улица, дом...).
     *              attributes - атрибуты XML-ноды для экспорта
     *
     */
    public $xmlConfigs;

    /**
     * @var array Массив полей для экспорта. Ключ разделен точкой, где значение до точки - префикс обрабатываемого файла
     *              после точки - атрибут. Значение - с каким именем будет передано значение поля
     */
    public $fields;

    private $_availableVersions = [];

    private $_log;

    /**
     * @var \XMLReader
     */
    private $_xmlReader;

    /**
     * @var int Кол-во отправленных в очередь элементов
     */
    private $_exportedItemsNumber = 0;

    private $_version;

    const TYPE_TO_PRIORITY = [
        'region' => 5,
        'district' => 4,
        'city' => 3,
        'street' => 2,
        'building' => 1,
    ];

    /**
     * @var bool Имитировать экспорт в очередь. Если true, задачи в RabbitMQ уходить не будут
     */
    private $_simulateExport = false;

    public function init()
    {
        parent::init();
        if (!file_exists(Yii::getAlias($this->fiasFolderPath))) {
            if (mkdir(Yii::getAlias($this->fiasFolderPath), 0777, true) === false) {
                throw new Exception("Can't create " . Yii::getAlias($this->fiasFolderPath));
            }
        }

        if (!file_exists(Yii::getAlias($this->fiasXmlFolderPath))) {
            if (mkdir(Yii::getAlias($this->fiasXmlFolderPath), 0777, true) === false) {
                throw new Exception("Can't create " . Yii::getAlias($this->fiasXmlFolderPath));
            }
        }

        if (!file_exists(Yii::getAlias($this->fiasLogFolderPath))) {
            if (mkdir(Yii::getAlias($this->fiasLogFolderPath), 0777, true) === false) {
                throw new Exception("Can't create " . Yii::getAlias($this->fiasLogFolderPath));
            }
        }

        $this->_log = $this->_readLog();

        $this->_availableVersions = $this->_getXMLVersionsFromFIAS();
    }

    /**
     * Экспорт полной базы и дельт
     *
     * @return $this|bool
     */
    public function export($simulateExport)
    {
        $this->_simulateExport = $simulateExport;

        $this->_version = $this->_log['lastVersion'] . '_' . time();

        $this->_xmlReader = new \XMLReader();

        $needFullExport = false;

        $exportDeltas = true;

        $importCurrentState = $this->_getImportCurrentsState();

        if ($importCurrentState === false){
            throw new Exception('Can\'t get import current state');
        }

        if ($importCurrentState->sync_status == self::STATE_IMPORT_PROGRESS) {
            echo 'Import in progress' . PHP_EOL;
            return false;
        }
        
        if ($importCurrentState->sync_status == self::STATE_FINISH_ERROR || empty($importCurrentState->sync_status)) {
            $needFullExport = true;
        }

        if ($needFullExport === true) {
            $this->_exportFullBase();
            $exportDeltas = false;
            HistoryChain::deleteAll();
        }

        if ($exportDeltas === true && $importCurrentState->params['origVersion'] < $this->_log['lastVersion']){
            $this->_exportDeltas($importCurrentState->params['origVersion']);
        }

        $deletedChainsItems = HistoryChain::deleteAll();
        echo 'Delete from Redis: ' . $deletedChainsItems . ' items' . PHP_EOL;

        return $this;
    }

    /**
     * Экспорт полной базы ФИАС
     *
     * @return boolean
     * @throws Exception
     */
    private function _exportFullBase()
    {
        $this->downloadFullBase();

        $this->_sendJobStart();

        foreach ($this->xmlConfigs as $filePrefix => $xmlConfig) {
            // Берем из конфига префиксы файлов, которые надо обрабатывать
            $xmlFiles = glob(Yii::getAlias($this->fiasXmlFolderPath) . DIRECTORY_SEPARATOR . $filePrefix . '_*');
            if (!empty($xmlFiles)) {
                //Обход всех xml файлов
                foreach ($xmlFiles as $xmlFilePath) {
                    $fileParts = explode(DIRECTORY_SEPARATOR, $xmlFilePath);
                    $xmlFileName = array_pop($fileParts);

                    echo 'Processing ' . $xmlFileName . PHP_EOL;
                    $exportResult = $this->_exportItems(
                        $xmlConfig,
                        $xmlFilePath,
                        $filePrefix,
                        $xmlFileName
                        );

                    if ($exportResult === true){
                        $this->_sendJobDeleteUnsynced();
                        $this->_sendJobFinish();
                    }
                }
            } else {
                throw new Exception('Folder with full base is empty');
            }
        }

        return false;
    }

    /**
     * Загрузка полной версии ФИАС
     *
     * @return $this
     */
    public function downloadFullBase()
    {
        if ($this->_log['lastVersion'] >= end($this->_availableVersions)['VersionId'])
        {
            return $this;
        }

        //Удаление старой версии
        $rootXml = glob(Yii::getAlias($this->fiasXmlFolderPath) . DIRECTORY_SEPARATOR . '*.[xX][mM][lL]');

        if (!empty($rootXml)) {
            foreach ($rootXml as $file) {
                unlink($file);
            }
        }

        $lastVersion = end($this->_availableVersions);
        echo "Last version: " . $lastVersion['VersionId'] . PHP_EOL;
        $rarFile = Yii::getAlias($this->fiasXmlFolderPath) . DIRECTORY_SEPARATOR . 'fias_xml.rar';

        if (file_exists($rarFile)) {
            unlink($rarFile);
        }

        $downloadResult = $this->_downloadChunked(
            $lastVersion['FiasCompleteXmlUrl'],
            $rarFile,
            $this->_curlGetFileSize($lastVersion['FiasCompleteXmlUrl'])
        );

        if ($downloadResult === true) {
            $this->_openRar($rarFile, Yii::getAlias($this->fiasXmlFolderPath));
        }

        //Ставим отметку о скачивании новой версии
        $this->_log['lastVersion'] = $lastVersion['VersionId'];
        $this->_saveLog();

        return $this;
    }

    /**
     * @param integer $fromVersion
     * @return bool
     * @throws Exception
     */
    private function _exportDeltas($fromVersion)
    {
        $deltasFolders = glob(Yii::getAlias($this->fiasXmlFolderPath) . DIRECTORY_SEPARATOR . '[0-9]*');

        foreach ($deltasFolders as $folder){
            $fileParts = explode(DIRECTORY_SEPARATOR, $folder);
            $folderName = array_pop($fileParts);

            if ((int)$folderName > $fromVersion){

                $this->_sendJobStart();

                foreach ($this->xmlConfigs as $filePrefix => $xmlConfig) {
                    // Берем из конфига префиксы файлов, которые надо обрабатывать
                    $xmlFiles = glob($folder . DIRECTORY_SEPARATOR . $filePrefix . '_*');
                    if (!empty($xmlFiles)) {
                        //Обход всех xml файлов
                        foreach ($xmlFiles as $xmlFilePath) {
                            $fileParts = explode(DIRECTORY_SEPARATOR, $xmlFilePath);
                            $xmlFileName = array_pop($fileParts);

                            echo 'Processing ' . $xmlFileName . PHP_EOL;
                            $exportResult = $this->_exportItems(
                                $xmlConfig,
                                $xmlFilePath,
                                $filePrefix,
                                $xmlFileName
                            );

                            if ($exportResult === true){
                                $this->_sendJobFinish();
                            }
                        }
                    } else {
                        throw new Exception('Folder with full base is empty');
                    }
                }

                return false;
            }
        }
    }

    /**
     * Загрузка дельт
     *
     * @return $this
     */
    public function downloadDeltas()
    {
        //Обход всех версий, которые старше последней загруженной
        foreach ($this->_availableVersions as $versionNum => $version) {
            if ($versionNum <= $this->_log['lastVersion']) {
                continue;
            }

            $deltaFolder = Yii::getAlias($this->fiasXmlFolderPath) . DIRECTORY_SEPARATOR . $versionNum;
            if (!file_exists($deltaFolder)) {
                mkdir($deltaFolder, 0777, true);
            }
            $deltaFileUrl = $version['FiasDeltaXmlUrl'];
            $deltaFileName = basename($deltaFileUrl);

            echo "Downloading delta v." . $versionNum . "\n";

            try {
                $this->_downloadChunked(
                    $deltaFileUrl,
                    $deltaFolder . DIRECTORY_SEPARATOR . $deltaFileName,
                    $this->_curlGetFileSize($deltaFileUrl));

                $this->_openRar($deltaFolder . DIRECTORY_SEPARATOR . $deltaFileName, $deltaFolder);
            } catch (\Exception $e) {
                echo $e->getMessage() . PHP_EOL;
            }

            //Ставим отметку о скачивании новой версии
            $this->_log['lastVersion'] = $versionNum;
            $this->_saveLog();
        }

        return $this;
    }

    /**
     * Экспорт всех записей из XML
     *
     * @param $xmlConfig
     * @param $xmlFilePath
     * @param $filePrefix
     * @param $xmlFileName
     * @param $delta
     *
     * @return bool
     */
    private function _exportItems($xmlConfig, $xmlFilePath, $filePrefix, $xmlFileName, $delta = false)
    {
        $startFrom = $this->_getProcessedItemsNumber($xmlFileName);
        $lineNumber = $startFrom+1;

        $logFile = Yii::getAlias($this->fiasLogFolderPath) . DIRECTORY_SEPARATOR . $xmlFileName . '.log';
        $logFileHandler = fopen($logFile, 'w');

        $nodeSizeBytes = 0;
        $fileSize = filesize($xmlFilePath);

        $this->_xmlReader->open($xmlFilePath);

        while ($this->_xmlReader->read() && $this->_xmlReader->localName !== $xmlConfig['item']) {
            continue;
        }

        while ($this->_xmlReader->localName == $xmlConfig['item']) {
            $nodeSizeBytes += (strlen($this->_xmlReader->readOuterXml()));
            $progress = number_format($nodeSizeBytes/$fileSize*100, 2);
            echo 'Progress is ' . $progress . "%\r";

            $this->_xmlReader->read();

            if ($lineNumber > $startFrom) {
                $type = is_callable($xmlConfig['type']) ? $xmlConfig['type']($this->_xmlReader) : $xmlConfig['type'];
                if (!empty($type)) {
                    try {
                        $makeHistoryChains = $delta ? false : $xmlConfig['historyChains'];
                        $this->_exportItem($xmlConfig['attributes'], $filePrefix, $type, $makeHistoryChains);
                    } catch (\Exception $e) {
                        echo $e->getMessage() . PHP_EOL;
                        fwrite($logFileHandler, $lineNumber-1);
                        fclose($logFileHandler);
                        return false;
                    }
                }
            }
            $lineNumber++;
        }

        echo 'Processed ' . $lineNumber . ' lines' . PHP_EOL;
        echo 'Send in queue ' . $this->_exportedItemsNumber . ' items' . PHP_EOL;

        fwrite($logFileHandler, $lineNumber);
        fclose($logFileHandler);

        return true;
    }

    /**
     * Экспорт одной записи
     *
     * @param $attributes
     * @param $filePrefix
     * @param $makeHistoryChain
     *
     * @return bool
     * @throws Exception
     */
    private function _exportItem($attributes, $filePrefix, $type, $makeHistoryChain)
    {
        $prevId = 0;
        $id = $this->_xmlReader->getAttribute('AOID');
        $guid = $this->_xmlReader->getAttribute('AOGUID');
        $liveStatus = $this->_xmlReader->getAttribute('LIVESTATUS');

        if ($makeHistoryChain === true) {
            $prevId = $this->_xmlReader->getAttribute('PREVID');
            $nextId = $this->_xmlReader->getAttribute('NEXTID');

            if (!empty($prevId) || !empty($nextId)) {
                $historyChain = new HistoryChain();
                $historyChain->id = 'fias:' . $id;
                $historyChain->prevId = $prevId;
                $historyChain->guid = $guid;
                $historyChain->save();
            }
        }

        if (empty((int) $liveStatus)){
            return false;
        }

        $fields = [];
        foreach ($attributes as $attribute) {
            $attributeValue = $this->_xmlReader->getAttribute($attribute);
            if (isset($fields[$this->fields[$filePrefix . '.' . $attribute]])){
                $fields[$this->fields[$filePrefix . '.' . $attribute]] = $fields[$this->fields[$filePrefix . '.' . $attribute]] . '/' . $attributeValue;
            } else {
                $fields[$this->fields[$filePrefix . '.' . $attribute]] = $attributeValue;
            }
        }

        $send = $this->_sendJobUpdate($fields, $type, $makeHistoryChain, $prevId);

        if ($send !== true){
            throw new Exception('Item export error');
        }

        return true;
    }

    /**
     * Построение цепочки предыдущих GUID
     *
     * @param $prevId
     * @return array
     */
    private function _getHistoryIds($prevId)
    {
        $chain = [];

        while (true){
            $historyChain = HistoryChain::findOne('fias:' . $prevId);
            if (empty($historyChain)){
                return $chain;
            }

            if (!in_array($historyChain->guid, $chain)) {
                $chain[] = $historyChain->guid;
            }

            $prevId = $historyChain->prevId;
            $historyChain->delete();
        }
    }

    /**
     * Чтение лога для обрабатываемого XML. В логе указан номер записи, которая обрабатывается в текущий
     * момент или на которой остановился экспорт
     *
     * @param $xmlFileName
     * @return int
     */
    private function _getProcessedItemsNumber($xmlFileName)
    {
        $logFile = Yii::getAlias($this->fiasLogFolderPath) . DIRECTORY_SEPARATOR . $xmlFileName . '.log';
        $prevCnt = 0;

        if (file_exists($logFile)) {
            $prevCnt = (int)file_get_contents($logFile);
        }

        return $prevCnt;
    }

    /**
     * Чтение локального лога парсера
     *
     * @return array|mixed
     */
    private function _readLog()
    {
        /*
         * Читаем из лога информацию о ранее загруженных версиях,
         * если раньше загрузок не было, создаем лог и начинаем с первой (365)
         */
        $fiasLogFile = Yii::getAlias($this->fiasFolderPath) . DIRECTORY_SEPARATOR . $this->fiasLogFile;
        if (!file_exists($fiasLogFile)) {
            $fiasLogData = [
                'lastVersion' => $this->firstVersionNum
            ];
            $logHandler = fopen($fiasLogFile, 'w');
            fwrite($logHandler, json_encode($fiasLogData));
        } else {
            $logHandler = fopen($fiasLogFile, 'r');
            $fiasLogData = json_decode(fread($logHandler, 1024), true);
        }
        fclose($logHandler);

        return $fiasLogData;
    }

    /**
     * Запись текущего состояния лога в фаил
     *
     * @return $this
     */
    private function _saveLog()
    {
        $fiasLogFile = Yii::getAlias($this->fiasFolderPath) . DIRECTORY_SEPARATOR . $this->fiasLogFile;
        $logHandler = fopen($fiasLogFile, 'w');
        fwrite($logHandler, json_encode($this->_log));
        fclose($logHandler);

        return $this;
    }

    /**
     * Список актуальных доступных версий на ФИАС
     *
     * @return array
     */
    private function _getXMLVersionsFromFIAS()
    {
        $result = [];
        $soap = new \SoapClient($this->fiasWsdl, [
            'exceptions' => true,
        ]);
        $soapRes = $soap->GetAllDownloadFileInfo();
        $xmlVersions = $soapRes->GetAllDownloadFileInfoResult->DownloadFileInfo;

        foreach ($xmlVersions as $key => $version) {
            $result[$version->VersionId] = (array)$version;
        }

        return $result;
    }

    /**
     * Пакетное чтение файлов
     *
     * @param $filePath
     * @param $localFile
     * @param $fileSize
     * @return boolean
     * @throws Exception
     */
    private function _downloadChunked($filePath, $localFile, $fileSize)
    {
        $chunksize = 1 * (1024 * 1024);
        $bytesNumber = 0;
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        $handle = @fopen($filePath, 'rb');
        if ($handle === false) {
            throw new Exception("Can't read remote file {$filePath}");
        }

        $fileHandle = fopen($localFile, 'a');
        if ($fileHandle === false) {
            throw new Exception("Can't open local file");
        }

        while (!feof($handle)) {
            $buffer = fread($handle, $chunksize);
            fwrite($fileHandle, $buffer);
            $bytesNumber += strlen($buffer);

            if ($fileSize !== false) {
                $progress = number_format($bytesNumber / $fileSize * 100, 2);
                echo " Progress: {$progress}%\r";
            } else {
                echo " Downloaded {$bytesNumber} bytes\r";
            }
        }
        echo "\n";
        fclose($fileHandle);
        fclose($handle);

        return $fileSize === false ? true : $bytesNumber == $fileSize;
    }

    /**
     * Получение размеров загружаемых файлов
     *
     * @param $url
     * @return bool|int
     */
    private function _curlGetFileSize($url)
    {
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_NOBODY, true);
        curl_setopt($curl, CURLOPT_HEADER, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);

        $data = curl_exec($curl);
        $info = curl_getinfo($curl);
        curl_close($curl);

        if ($info['http_code'] == 200 || ($info['http_code'] > 300 && $info['http_code'] <= 308)) {
            return isset($info['download_content_length']) ? $info['download_content_length'] : false;
        }

        return false;
    }

    /**
     * @param $rarFile
     * @throws Exception
     */
    private function _openRar($rarFile, $unpackFolder)
    {
        if (!file_exists($unpackFolder)) {
            mkdir($unpackFolder);
        }

        $chunksize = 1 * (1024 * 1024);
        //\RarException::setUsingExceptions(true);
        $rar = \RarArchive::open($rarFile);
        if ($rar === false) {
            throw new Exception("Can't open archive file");
        }

        $files = $rar->getEntries();
        if (!empty($files)) {
            foreach ($files as $file) {
                $filePrefixes = array_keys($this->xmlConfigs);
                $fileName = $file->getName();

                foreach ($filePrefixes as $filePrefix) {
                    if (preg_match('#^' . $filePrefix . '.*#i', $fileName, $matchFileName)) {
                        $fileSize = $file->getUnpackedSize();
                        $fileHandle = fopen($unpackFolder . DIRECTORY_SEPARATOR . $fileName, 'a');
                        $stream = $file->getStream();
                        $bytesNumber = 0;

                        while (!feof($stream)) {
                            $buffer = fread($stream, $chunksize);
                            $bytesNumber += strlen($buffer);
                            if ($buffer !== false) {
                                if (fwrite($fileHandle, $buffer) === false) {
                                    throw new Exception("Can't extract {$fileName}");
                                }
                                $progress = number_format($bytesNumber / $fileSize * 100, 2);
                                echo 'Extracting ' . $fileName . ": " . $progress . "%\r";
                            }
                        }
                        fclose($stream);
                        fclose($fileHandle);
                        echo "\n";
                    }
                }
            }
        }
        if ($rar->close() === true) {
            @unlink($rarFile);
        }
    }

    /**
     * Получение состояния импорта на стороне словарей
     *
     * @return string
     */
    private function _getImportCurrentsState()
    {
        $job = new DictionaryViewJob();
        $job->dictionary_sn = self::DICTIONARY_SN;
        $result = $job->findOne();

        return $result;
    }

    /**
     * Отправка RPC для удаления всех неудачно синхранизированных записей
     *
     * @return bool
     */
    private function _sendJobDeleteUnsynced()
    {
        if ($this->_simulateExport === true){
            return true;
        }

        $job = new ItemDeleteUnsyncedJob();
        $job->sync_version = (string)$this->_version;
        $job->dictionary_sn = self::DICTIONARY_SN;
        $job->send();

        $this->_exportedItemsNumber++;
        
        return true;
    }

    /**
     * @return bool
     */
    private function _sendJobStart()
    {
        if ($this->_simulateExport === true){
            return true;
        }

        $job = new StartJob();
        $job->sync_version = (string)$this->_version;
        $job->dictionary_sn = self::DICTIONARY_SN;
        $job->params = [
            'origVersion' => $this->_log['lastVersion'],
        ];
        $job->send();

        return true;
    }

    /**
     * @param $fields
     * @param $type
     * @param $makeHistoryChain
     * @param $prevId
     * @return bool
     */
    private function _sendJobUpdate($fields, $type, $makeHistoryChain, $prevId)
    {
        if ($this->_simulateExport === true){
            $this->_exportedItemsNumber++;
            return true;
        }

        $job = new ItemUpdateJob();
        $job->load($fields, '');

        $job->setPriority(self::TYPE_TO_PRIORITY[$type]);

        $job->sync_version      = (string)$this->_version;
        $job->dictionary_sn     = self::DICTIONARY_SN;
        $job->item_type_sn      = $type;
        $job->prev_external_ids = $makeHistoryChain === true && !empty($prevId) ? $this->_getHistoryIds($prevId) : [];
        //TODO:
        $job->title = ['ru_RU' => $fields['title']];

        $job->send();

        $this->_exportedItemsNumber++;

        return true;
    }

    /**
     * @param integer $itemsCount Количество отправленных записей
     * @return bool
     */
    private function _sendJobFinish()
    {
        if ($this->_simulateExport === true){
            return true;
        }

        $job = new FinishJob();
        $job->sync_version = (string)$this->_version;
        $job->dictionary_sn = self::DICTIONARY_SN;
        $job->job_amount = $this->_exportedItemsNumber;
        $job->send();

        $this->_exportedItemsNumber = 0;

        return true;
    }
}