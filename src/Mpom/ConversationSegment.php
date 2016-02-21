<?php
/**
 * @author Silvan
 */

namespace Mpom;


use ezcMailAddress;
use ezcMailComposer;
use Google_Service_Gmail;
use Google_Service_Gmail_Message;
use Symfony\Component\Console\Output\OutputInterface;

class ConversationSegment
{
    /** @var \PDO */
    protected $dbh;

    /** @var  \PDO */
    protected $statusDbh;

    protected $messages = [];

    /** @var integer */
    protected $convoId = null;

    /** @var string */
    protected $displayname = '';

    /** @var OutputInterface */
    protected $output;

    /**
     * @var Gmail
     */
    protected $gmail = null;

    /**
     * @var array
     */
    protected $config;

    public function __construct(Gmail $gmail, \PDO $dbh, \PDO $statusDbh, OutputInterface $output, $config)
    {
        $this->gmail = $gmail;
        $this->dbh = $dbh;
        $this->statusDbh = $statusDbh;
        $this->output = $output;
        $this->config = $config;
    }

    /**
     * @return null
     */
    public function getConvoId()
    {
        return $this->convoId;
    }

    public function addMessage($timestamp, $from, $message, $convo_id, $message_id, $displayname)
    {

        if (!is_null($this->convoId)) {
            if ($convo_id != $this->convoId) {
                throw new \Exception("All messages added to a conversation segment must have the same convo_id");
            }
        }
        $this->convoId = $convo_id;
        $this->displayname = $displayname;

        $this->messages[] = [
            'convo_id' => $convo_id,
            'message_id' => $message_id,
            'timestamp' => $timestamp,
            'from' => $this->resolveNameToEmail($from),
            'message' => $this->filterMessageText($message),
        ];
    }

    public function getNumberOfMessages()
    {
        return count($this->messages);
    }

    public function getMaxTimestamp()
    {
        $max = 0;
        foreach ($this->messages as $message) {
            if ($message['timestamp'] > $max) {
                $max = $message['timestamp'];
            }
        }

        return $max;
    }

    /** Get all conversation partners */
    public function getPartnersSkypename()
    {
        $partners = [];
        foreach ($this->messages as $message) {
            if (isset($partners[$message['from']['name']])) {
                $partners[$message['from']['name']]['count']++;
            } else {
                $partners[$message['from']['name']] = [
                    'skypename' => $message['from']['skypename'],
                    'email' => $message['from']['email'],
                    'name' => $message['from']['name'],
                    'count' => 1,
                ];
            }
        }
        usort(
            $partners,
            function ($a, $b) {
                return $a['count'] < $b['count'];
            }
        );

        return $partners;
    }

    /**
     * Store it in the gmail service
     */
    public function store()
    {
        $mail = new ezcMailComposer();

        $first = true;
        foreach ($this->getPartnersSkypename() as $partner) {
            $address = new ezcMailAddress(
                empty($partner['email']) ? $partner['skypename'] . '@unknown.com' : $partner['email'], $partner['name']
            );
            if ($first && $partner['skypename']) {
                $mail->from = $address;
                $first = false;
            } else {
                $mail->addTo($address);
            }
        }

        // Specify the subject of the mail
        $mail->subject = $this->displayname;
        // Specify the body text of the mail
        $mail->htmlText = $this->toHtml();
        // Generate the mail
        $mail->build();
        $mail->setHeader("Date", date('r', $this->getMaxTimestamp()));

        $mailWithCorrectDate = preg_replace(
            '/^Date:.*$/m',
            'Date: ' . date('r', $this->getMaxTimestamp()),
            $mail->generate()
        );

        $msgbody = new Google_Service_Gmail_Message();
        $msgbody->setRaw(rtrim(strtr(base64_encode($mailWithCorrectDate), '+/', '-_'), '='));
        $msgbody->setLabelIds([$this->config['label']]);
        $service = new Google_Service_Gmail($this->gmail->getClient());
        $service->users_messages->insert('me', $msgbody, ['internalDateSource' => 'dateHeader']);
        if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $this->output->writeln("Storing conversation with subject '{$mail->subject}' and date '" . date('r',
                    $this->getMaxTimestamp()) . "'");
        }
    }

    protected function filterMessageText($text)
    {
        return strip_tags($text, '<pre><b>');
    }

    protected function resolveNameToEmail($skypename)
    {
        // Using manually filled table "skypenameToEmail"
        $query = $this->statusDbh->prepare("SELECT email, name FROM skypenameToEmail WHERE skypename=:skypename ");
        $query->execute([':skypename' => $skypename]);

        if ($row = $query->fetch(\PDO::FETCH_ASSOC)) {
            return ['skypename' => $skypename, 'email' => $row['email'], 'name' => $row['name']];
        }

        // Using table "Contacts"
        $query = $this->dbh->prepare("SELECT displayname FROM Contacts WHERE skypename=:skypename");

        $query->execute([':skypename' => $skypename]);

        if ($row = $query->fetch(\PDO::FETCH_ASSOC)) {
            return ['skypename' => $skypename, 'email' => null, 'name' => $row['displayname']];
        }

        return ['skypename' => $skypename, 'email' => null, 'name' => $skypename];
    }

    public function toHtml()
    {
        $out = '<table>';
        $previousName = null;
        foreach ($this->messages as $message) {

            $out .= '<tr>';

            if ($message['from']['name'] != $previousName) {
                $out .= '<td style="vertical-align: top; white-space: nowrap">'
                    . date(
                        'D, j. M Y H:i',
                        $message['timestamp']
                    )
                    . "</td><td style='vertical-align: top; white-space: nowrap'><b>" . $message['from']['name']
                    . "</b></td>";
            } else {
                $out .= '<td style="vertical-align: top; white-space: nowrap"></td><td></td>';
            }
            $previousName = $message['from']['name'];

            $out .= "<td style='vertical-align: top'>" . $message['message'] . "</td></tr>";
        }

        $out .= "</table>";

        return $out;
    }

    public function __toString()
    {
        $out = '';

        foreach ($this->messages as $message) {
            $out .= $message['convo_id'] . " " . $message['message_id'] . " " . date(
                    'r',
                    $message['timestamp']
                ) . " " . $message['from']['name'] . ": " . $message['message'] . "\n";
        }

        return $out;
    }
}