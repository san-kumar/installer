<?php
/**
 * Created by PhpStorm.
 * User: san
 * Date: 24/10/17
 * Time: 5:38 PM
 */

namespace Console\App {

    use Console\Command\ConfigAws;
    use Console\Command\Deploy;
    use Symfony\Component\Console\Application;

    class ConsoleApp {
        /**
         * @var Application
         */
        private $app;
        /**
         * @var Commands
         */
        private $commands;

        public function __construct(Application $app, Commands $commands) {
            $this->app = $app;
            $this->commands = $commands;
        }

        public function run() {
            $this->app->setName('DirtyPHP');
            $this->app->setVersion('0.01');

            $this->app->addCommands($this->commands->getCommands());

            $this->app->run();
        }
    }
}