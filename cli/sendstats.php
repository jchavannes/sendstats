<?
include('common.php');

Database::loadDb();

$logFiles = array(); /** @var $logFiles LogFile[] */
$directory = "/var/log/apache2/";
foreach (preg_grep('/access[A-Za-z-]*.log$/', scandir($directory)) as $filename) {
    $logFiles[] = new LogFile($filename, $directory);
}

$updatedLogFiles = array();
foreach ($logFiles as $logFile) {
    if ($logFile->fileChanged) {
        $updatedLogFiles[] = $logFile;
    }
}

if (count($updatedLogFiles)) {
    $payload = new Payload($updatedLogFiles);

    $message = serialize($payload);
    $response = Stream::send($message);

    echo $response . "\n";
    if ($response == "success") {
        LineCount::save($updatedLogFiles);
        Database::commit();
    }
    else {
        Database::rollback();
    }
}
else {
    echo "No updates.\n";
}

class LineCount {

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

    static public function getPreviousStats(LogFile &$logFile) {
        $selectQuery = "
            SELECT
              lineCount,
              fileSize,
              firstEntry
           FROM file_info
           WHERE filename = ?";
        $stmt = Database::$handle->prepare($selectQuery);
        $stmt->bind_param("s", $logFile->filenameWithDirectory);
        $stmt->execute();
        try {
            $stmt->bind_result($lineCount, $fileSize, $firstEntry);
            $stmt->fetch();
            $logFile->previousLineCount  = $lineCount;
            $logFile->previousFileSize   = $fileSize;
            $logFile->previousFirstEntry = $firstEntry;
            $stmt->close();
            return true;
        } catch(Exception $e) {
            $stmt->close();
            return false;
        }
    }

    /**
     * @param $logFiles LogFile[]
     */
    static public function save($logFiles) {
        $contents = "";
        foreach ($logFiles as $logFile) {
            $contents .= sprintf(
                "%s %d %d %d\n",
                $logFile->filenameWithDirectory,
                $logFile->currentLineCount,
                $logFile->currentFileSize,
                $logFile->currentFirstEntry
            );
            $deleteQuery = "DELETE FROM file_info WHERE filename = ?";
            $stmt = Database::$handle->prepare($deleteQuery);
            $stmt->bind_param("s", $logFile->filenameWithDirectory);
            $stmt->execute();

            $insertQuery = "
                INSERT INTO file_info (fileName, lineCount, fileSize, firstEntry)
                VALUES (?, ?, ?, ?)";
            $stmt = Database::$handle->prepare($insertQuery);
            $stmt->bind_param(
                "sddd",
                $logFile->filenameWithDirectory,
                $logFile->currentLineCount,
                $logFile->currentFileSize,
                $logFile->currentFirstEntry
            );
            $stmt->execute();
        }

    }

}
