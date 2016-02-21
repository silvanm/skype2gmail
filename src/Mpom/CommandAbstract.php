<?php

namespace Mpom;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class CommandAbstract
{

    protected $config;

    /** @var \PDO */
    protected $dbh;

    /** @var  \PDO */
    protected $statusDbh;

    /** @var  Gmail */
    protected $gmail;

    /** @var InputInterface */
    protected $input;

    /** @var OutputInterface */
    protected $output;

    public function __construct($config, InputInterface $input, OutputInterface $output)
    {
        $this->config = $config;

        $this->input = $input;
        $this->output = $output;
    }

    public function initDbConnection()
    {
        try {
            $this->dbh = new \PDO($this->config['skypeDsn']);
        } catch (\Exception $e) {
            $this->output->writeln(
                sprintf("<error>Error during setting up connection to Skype-database %s. DB-String is %s.</error>",
                $e->getMessage(), $this->config['skypeDsn']));
            return false;
        }

        try {
            $this->statusDbh = new \PDO($this->config['statusDbDsn']);
        } catch (\Exception $e) {
            $this->output->writeln(
                sprintf("<error>Error during setting up connection to Skype-database %s. DB-String is %s.</error>",
                    $e->getMessage(), $this->config['statusDbDsn']));
            return false;
        }

        return true;
    }

    /**
     * @return Gmail
     */
    public function initGmailConnection()
    {
        if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $this->output->writeln("Init Gmail Connection");
        }

        $gmail = new Gmail($this->config);
        $gmail->getClient();
        $this->gmail = $gmail;
        return $gmail;
    }

    abstract public function run();
}