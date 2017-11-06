<?php
/**
 * Created by PhpStorm.
 * User: san
 * Date: 24/10/17
 * Time: 6:20 PM
 */
namespace Console\App {

    use Console\Command\ConfigAws;
    use Console\Command\Deploy;

    class Commands {
        private $commands;

        /**
         * Commands constructor.
         * @param ConfigAws $config
         * @param Deploy $deploy
         */
        public function __construct(ConfigAws $config, Deploy $deploy) {
            $this->commands = func_get_args();
        }

        /**
         * @return array
         */
        public function getCommands(): array {
            return $this->commands;
        }
    }
}