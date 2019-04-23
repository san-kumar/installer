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
    use Console\Command\Remove;

    class Commands {
        private $commands;

        /**
         * Commands constructor.
         * @param ConfigAws $config
         * @param Deploy $deploy
         * @param Remove $remove
         */
        public function __construct(ConfigAws $config, Deploy $deploy, Remove $remove) {
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