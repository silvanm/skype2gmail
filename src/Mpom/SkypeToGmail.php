<?php
/**
 * @author Silvan
 */

namespace Mpom;


class SkypeToGmail
{

    protected $config;

    /** @var \PDO */
    protected $dbh;

    /** @var  \PDO */
    protected $statusDbh;

    /** @var  Gmail */
    protected $gmail;

    public function __construct($config)
    {
        $this->config    = $config;
        $this->dbh       = new \PDO($config['dsn']);
        $this->statusDbh = new \PDO($config['statusDbDsn']);

        $gmail = new Gmail($config);
        $gmail->getClient();

        $this->gmail = $gmail;

        $this->initStatusDb();
    }

    public function getConversations()
    {
        $convoQuery = $this->dbh->query(
            "SELECT id, displayname
        FROM Conversations WHERE last_activity_timestamp < (strftime('%s', 'now') - :timeout)
        "
        );
        $convoQuery->execute([':timeout' => $this->config['inactivityTriggerSeconds']]);

        foreach ($convoQuery->fetchAll() as $conversation) {

            $minTimestamp = $this->getLastMessageTimestampByConversation($conversation['id']);

            if (is_null($minTimestamp)) {
                $minTimestamp = 0;
            }

            $messageQuery = $this->dbh->query(
                "SELECT id, convo_id, timestamp, author, body_xml
                FROM Messages
               WHERE convo_id = :convoid
               AND timestamp > :timestamp
               ORDER BY timestamp
                "
            );
            $messageQuery->execute([':convoid' => $conversation['id'], ':timestamp' => $minTimestamp]);

            $lastTimestamp = null;
            foreach ($messageQuery->fetchAll() as $message) {
                if ($lastTimestamp == null || ( $message['timestamp'] - $lastTimestamp ) > $this->config['inactivityTriggerSeconds']) {
                    if ( ! empty( $segment )) {
                        $this->storeSegmentAndUpdatePointer($segment);
                    }
                    $segment = new ConversationSegment($this->gmail, $this->dbh, $this->statusDbh);

                }
                $segment->addMessage(
                    $message['timestamp'],
                    $message['author'],
                    $message['body_xml'],
                    $message['convo_id'],
                    $message['id'],
                    $conversation['displayname']
                );
                $lastTimestamp = $message['timestamp'];
            }
            if (isset( $segment )) {
                $this->storeSegmentAndUpdatePointer($segment);
            }
            $segment = null;
        }
    }

    public function initStatusDb()
    {
        $query = $this->statusDbh->query("SELECT name FROM sqlite_master WHERE name='importPointer'");
        $query->execute();
        if ($query->rowCount() == 0) {
            $this->statusDbh->exec(
                "CREATE TABLE `importPointer` (
            	`convo_id`	INTEGER,
            	`timestamp`	INTEGER,
            	PRIMARY KEY(convo_id)
            )"
            );

            $this->statusDbh->exec(
                "CREATE TABLE `skypenameToEmail` (
                    `skypename`	TEXT,
                    `email`	TEXT,
                    `name`	TEXT,
                    PRIMARY KEY(skypename)
                   );"
            );

        }
    }

    /**
     * @param $id int convo_id
     *
     * @return int
     */
    protected function getLastMessageTimestampByConversation($id)
    {
        $query = $this->statusDbh->prepare("SELECT timestamp FROM importPointer WHERE convo_id = :convoid");
        $query->execute([':convoid' => $id]);

        if ($row = $query->fetch(\PDO::FETCH_ASSOC)) {
            return $row['timestamp'];
        }

        return null;
    }

    protected function storeSegmentAndUpdatePointer(ConversationSegment $segment)
    {
        if ($segment->getNumberOfMessages() > 1) {
            $segment->store();

            $query = $this->statusDbh->prepare(
                "SELECT convo_id, timestamp FROM importPointer WHERE convo_id = :convoid"
            );
            $query->execute([':convoid' => $segment->getConvoId()]);

            // There is already a row
            if ($row = $query->fetch(\PDO::FETCH_ASSOC)) {
                if ($row['timestamp'] < $segment->getMaxTimestamp()) {
                    $query  = $this->statusDbh->prepare(
                        "UPDATE importPointer SET timestamp=:timestamp WHERE convo_id = :convoid"
                    );
                    $result = $query->execute(
                        [':convoid' => $segment->getConvoId(), ':timestamp' => $segment->getMaxTimestamp()]
                    );
                } else {
                    $result = true;
                }
            } else {
                // New row for this conversation
                $query = $this->statusDbh->prepare(
                    "INSERT INTO importPointer(`convo_id`, `timestamp`) VALUES (:convoid, :timestamp) "
                );
                echo "inserting : ".$segment->getConvoId()."\n";
                $result = $query->execute(
                    [':convoid' => $segment->getConvoId(), ':timestamp' => $segment->getMaxTimestamp()]
                );
            }
            if ( ! $result) {
                throw new \Exception("PDO-Exception: ".print_r($this->statusDbh->errorInfo(), true));
            }
        }
    }

}