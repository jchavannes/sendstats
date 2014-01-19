<?

define("DATABASE_HOST", "localhost");
define("DATABASE_USER", "root");
define("DATABASE_PASS", "root");
define("DATABASE_NAME", "sendstats");

$directory = "/var/log/apache2/";
$lineCountsFiles = "lastFileInfo.txt";

LineCount::loadSavedLineCounts($lineCountsFiles);

$logFiles = array();
foreach (preg_grep('/access[A-Za-z-]*.log$/', scandir($directory)) as $filename) {
    $logFiles[] = new LogFile($filename, $directory);
}

LineCount::saveLineCounts($logFiles, $lineCountsFiles);

class LogFile {

    public $projectName;
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
            $this->getNewTimeCounts();
            LineCount::updateDatabase($this);
            $this->currentLineCount = LineCount::getCurrentLineCount($this->filenameWithDirectory);
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
    }

}

class LineCount {

    static private $savedLineCounts;
    static private $previousStatsLoaded = false;
    static private $numFields = 4;

    /** @var $dbHandle mysqli */
    static private $dbHandle;

    static public function updateDatabase(LogFile $logFile) {
        if (!self::$dbHandle instanceof mysqli) {
            if (!self::$dbHandle = new mysqli(DATABASE_HOST, DATABASE_USER, DATABASE_PASS, DATABASE_NAME)) {
                throw new Exception("Unable to connect to database.");
            }
            else {
                self::$dbHandle->autocommit(false);
            }
        }
        // Make sure project exists in project table
        $findProjectQuery = "SELECT name FROM projects WHERE name = ?";
        $stmt = self::$dbHandle->prepare($findProjectQuery);
        $stmt->bind_param("s", $logFile->projectName);
        $stmt->execute();
        $stmt->bind_result($projectName);
        $stmt->fetch();

        // If project doesn't exist, add it
        if ($projectName != $logFile->projectName) {
            $addProjectQuery = "INSERT INTO projects (name) VALUES (?)";
            $stmt = self::$dbHandle->prepare($addProjectQuery);
            $stmt->bind_param("s", $logFile->projectName);
            $stmt->execute();
        }

        // Add / Update counts
        $selectQuery = "
            SELECT count
            FROM project_time_counts
            JOIN projects USING(projectId)
            WHERE projects.name = ?
            AND project_time_counts.time = ?";
        $updateQuery = "
            UPDATE project_time_counts
            JOIN projects USING(projectId)
            SET count = ?
            WHERE projects.name = ?
            AND project_time_counts.time = ?";
        $insertQuery = "
            INSERT INTO project_time_counts (projectId, time, count)
            VALUES ((SELECT projectId FROM projects WHERE name = ?), ?, ?)";
        foreach ($logFile->newTimeCounts as $time => $count) {
            $stmt = self::$dbHandle->prepare($selectQuery);
            $stmt->bind_param("sd", $logFile->projectName, $time);
            $stmt->execute();
            $stmt->bind_result($oldCount);
            $stmt->fetch();
            if ($oldCount > 0) {
                $count += $oldCount;
                $stmt = self::$dbHandle->prepare($updateQuery);
                $stmt->bind_param("dsd", $count, $logFile->projectName, $time);
                $stmt->execute();
            }
            else {
                $stmt = self::$dbHandle->prepare($insertQuery);
                $stmt->bind_param("sdd", $logFile->projectName, $time, $count);
                $stmt->execute();
            }
        }
    }

    static public function loadSavedLineCounts($filename) {
        if (!file_exists($filename)) {
            touch($filename);
            if (!file_exists($filename)) {
                throw new Exception("Cannot create count file ($filename).");
            }
        }
        self::$savedLineCounts = explode("\n", file_get_contents($filename));
        self::$previousStatsLoaded = true;
    }

    static public function getPreviousStats(LogFile &$logFile) {
        if (!self::$previousStatsLoaded) {
            throw new Exception("Must load line counts file first.");
        }
        foreach (self::$savedLineCounts as $line) {
            $fields = explode(" ", $line);
            if (count($fields) !== self::$numFields) {
                continue;
            }
            list($filename, $count, $fileSize, $firstEntry) = $fields;
            if ($filename == $logFile->filenameWithDirectory) {
                $logFile->previousLineCount  = $count;
                $logFile->previousFileSize   = $fileSize;
                $logFile->previousFirstEntry = $firstEntry;
                return true;
            }
        }
        return false;
    }

    static public function getFirstEntry($filename) {
        return self::parseDateFromLine(exec("head -n 1 " . escapeshellarg($filename)));
    }

    static public function parseDateFromLine($line) {
        $line = explode(" ", $line);
        if (count($line) < 4) {
            return 0;
        }
        list(,,,$date, $timeZone) = $line;
        return strtotime(str_replace(array("[", "]"), "", "$date $timeZone"));
    }

    static public function getCurrentLineCount($filename) {
        return (int) exec("wc -l " . escapeshellarg($filename) . " | awk '{print $1}'");
    }

    /**
     * @param $logFiles LogFile[]
     * @param $filename
     */
    static public function saveLineCounts($logFiles, $filename) {
        $contents = "";
        foreach ($logFiles as $logFile) {
            $contents .= sprintf(
                "%s %d %d %d\n",
                $logFile->filenameWithDirectory,
                $logFile->currentLineCount,
                $logFile->currentFileSize,
                $logFile->currentFirstEntry
            );
            if ($logFile->fileChanged) {
                $changeText = "+" . ($logFile->currentLineCount - $logFile->previousLineCount);
            }
            else {
                $changeText = "0";
            }
            $logMessage = sprintf(
                "%-6s %-45s %-7d %-7d %-12s\n",
                $changeText,
                $logFile->projectName,
                $logFile->currentLineCount,
                $logFile->previousLineCount,
                $logFile->currentFirstEntry > 0 ? date("Y-m-d H:i:s", $logFile->currentFirstEntry) : "None"
            );
            echo $logMessage;
        }
        file_put_contents($filename, $contents);
        if (self::$dbHandle instanceof mysqli) {
            self::$dbHandle->commit();
        }
    }

}
