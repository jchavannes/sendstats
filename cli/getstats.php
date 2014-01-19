<?
include('common.php');

Database::loadDb();

set_time_limit(0);
$server = stream_socket_server("tcp://" . STREAM_ADDR_SERVER . ":" . STEAM_PORT);

while ($conn = stream_socket_accept($server, 600)) {
    try {
        $length = fread($conn, STREAM_LENGTH);
        $raw = "";
        while ($partial = trim(fread($conn, STREAM_LENGTH))) {
            $raw .= $partial;
            if (strlen($raw) >= $length) {
                break;
            }
        }
        $payload = unserialize($raw); /** @var $payload Payload */
        if (!$payload instanceof Payload || !$payload->verifyToken()) {
            throw new Exception("Expecting token: " . $payload->getToken() . ", got token: " . $payload->token);
        }
        CollectStats::save($payload->logFiles);
        echo "return success (" . strlen($raw) . ")\n";
        fwrite($conn, "success");
        Database::commit();
    }
    catch (Exception $e) {
        echo "return failed (" . strlen($raw) . ")\n";
        fwrite($conn, "failed: " . $e->getMessage(), STREAM_LENGTH);
        Database::rollback();
    }
    fclose($conn);
}

fclose($server);

class CollectStats {

    /** @var $carbonSocket resource */
    static private $carbonSocket;

    /**
     * @param $logFiles LogFile[]
     */
    static public function save($logFiles) {
        foreach ($logFiles as $logFile) {
            self::updateDatabase($logFile);
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
    }

    static public function sendToGraphite($project, $time, $count) {
        if (self::$carbonSocket == null) {
            if (!self::$carbonSocket = fsockopen("localhost", 2003)) {
                throw new Exception("Unable to connect to carbon socket.");
            }
        }
        $message = "stats.apache.requests.$project $count $time\n";
        fwrite(self::$carbonSocket, $message, strlen($message));
    }

    static public function updateDatabase(LogFile $logFile) {

        // Make sure project exists in project table
        $findProjectQuery = "SELECT projectId FROM `projects` WHERE `name` = ? AND hostname = ?";
        $stmt = Database::$handle->prepare($findProjectQuery);
        $stmt->bind_param("ss", $logFile->projectName, $logFile->hostName);
        $stmt->execute();
        $stmt->bind_result($projectId);
        $stmt->fetch();
        $stmt->close();

        // If project doesn't exist, add it
        if ($projectId <= 0) {
            $addProjectQuery = "INSERT INTO projects (`name`, `hostname`) VALUES (?, ?)";
            $stmt = Database::$handle->prepare($addProjectQuery);
            $stmt->bind_param("ss", $logFile->projectName, $logFile->hostName);
            $stmt->execute();
            $projectId = $stmt->insert_id;
        }

        $projectId = (int) $projectId;

        $selectQuery = "
            SELECT `count`
            FROM project_time_counts
            WHERE `projectId` = ?
            AND `time` = ?";

        $updateQuery = "
            UPDATE project_time_counts
            SET `count` = ?
            WHERE `projectId` = ?
            AND `time` = ?";

        $insertQuery = "
            INSERT INTO project_time_counts (`projectId`, `time`, `count`)
            VALUES (?, ?, ?)";

        // Add / Update counts
        foreach ($logFile->newTimeCounts as $time => $count) {

            // Get current count
            $stmt = Database::$handle->prepare($selectQuery);
            $stmt->bind_param("dd", $projectId, $time);
            $stmt->execute();
            $stmt->bind_result($oldCount);
            $stmt->fetch();
            $stmt->close();

            // If count exists, update
            if ($oldCount > 0) {
                $count += $oldCount;
                $stmt = Database::$handle->prepare($updateQuery);
                $stmt->bind_param("ddd", $count, $projectId, $time);
                $stmt->execute();
            }

            // Otherwise, create new count
            else {
                $stmt = Database::$handle->prepare($insertQuery);
                $stmt->bind_param("ddd", $projectId, $time, $count);
                $stmt->execute();
            }

            // Send data to graphite
            self::sendToGraphite($logFile->projectName, $time, $count);
        }
    }

}