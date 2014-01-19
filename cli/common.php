<?

include("../credentials.php");

class Database {

    /** @var $dbHandle mysqli */
    static public $handle;

    static public function loadDb() {
        // Establish database connection
        if (!self::$handle instanceof mysqli) {
            if (!self::$handle = new mysqli(DATABASE_HOST, DATABASE_USER, DATABASE_PASS, DATABASE_NAME)) {
                throw new Exception("Unable to connect to database.");
            }
            else {
                self::$handle->autocommit(false);
            }
        }
    }

    static public function commit() {
        if (self::$handle instanceof mysqli) {
            self::$handle->commit();
        }
    }

    static public function rollback() {
        if (self::$handle instanceof mysqli) {
            self::$handle->rollback();
        }
    }

}

class Stream {

    static public function send($message) {

        try {
            $client = stream_socket_client("tcp://" . STREAM_ADDR_CLIENT . ":" . STEAM_PORT);
            fwrite($client, $message . "\n");
            $response = fread($client, STREAM_LENGTH);
            fclose($client);
            return $response;
        }
        catch (Exception $e) {
            return false;
        }

    }

}

class Payload {

    public $token;

    /** @var $logFiles LogFile[] */
    public $logFiles;

    public $hostname;

    public function __construct($logFiles) {
        $this->token = md5(round(time() / 60, 60) . TOKEN_CODE);
        $this->hostname = HOST_NAME;
        $this->logFiles = $logFiles;
    }

    static public function getToken() {
        return md5(round(time() / 60, 60) . TOKEN_CODE);
    }

    public function verifyToken() {
        return $this->token == md5(round(time() / 60, 60) . TOKEN_CODE);
    }

}

class LogFile {

    public $projectName;
    public $hostName;
    public $filename;
    public $filenameWithDirectory;

    public $previousLineCount;
    public $previousFileSize;
    public $previousFirstEntry;

    public $currentLineCount;
    public $currentFileSize;
    public $currentFirstEntry;

    public $fileChanged = false;
    private $handle;
    public $newTimeCounts = array();

    public function __construct($filename, $directory) {
        // Try to parse project name from file name
        $replaceArray = array("-access", "access-", "_access", "access_", ".log");
        $this->projectName = str_replace($replaceArray, "", $filename);
        $this->hostName = HOST_NAME;

        $this->filename = $filename;
        $this->filenameWithDirectory = $directory . $filename;

        // Load stats from last run
        LineCount::getPreviousStats($this);

        // Get current stats
        $this->getCurrentStats();
    }

    private function getCurrentStats() {

        $this->currentFileSize   = filesize($this->filenameWithDirectory);
        $this->currentFirstEntry = LineCount::getFirstEntry($this->filenameWithDirectory);

        // If first entries do not match then this is a new file
        if ($this->currentFirstEntry != $this->previousFirstEntry) {
            $this->previousLineCount = 0;
            $this->previousFileSize  = 0;
        }

        // If file sizes do not match, get new line count
        if ($this->currentFileSize != $this->previousFileSize) {
            $this->fileChanged = true;
            $this->currentLineCount = $this->getNewTimeCounts();
        }
        else {
            $this->currentLineCount = $this->previousLineCount;
        }
    }

    private function getNewTimeCounts() {
        $this->handle = fopen($this->filenameWithDirectory, 'r');
        $count = 0;
        while ($line = fgets($this->handle)) {
            if ($count++ < $this->previousLineCount) {
                continue;
            }
            $date = LineCount::parseDateFromLine($line);
            if ($date > 0) {
                if (!isset($this->newTimeCounts[$date])) {
                    $this->newTimeCounts[$date] = 0;
                }
                $this->newTimeCounts[$date]++;
            }
        }
        return $count;
    }

}
