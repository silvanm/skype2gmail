<?php

namespace Mpom\Command;

use Mpom\CommandAbstract;
use Symfony\Component\Console\Output\OutputInterface;

class Init extends CommandAbstract
{
    public function initStatusDb()
    {
        if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $this->output->writeln("Init status DB");
        }

        if (!$this->initDbConnection()) {
            return false;
        }

        // See if database has been initialized.
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

        return true;
    }

    public function run()
    {
        $this->initStatusDb();
        $this->initGmailConnection();
    }
}