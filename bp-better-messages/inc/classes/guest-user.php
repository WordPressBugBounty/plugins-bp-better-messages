<?php
if( ! class_exists( 'BM_Guest_User' ) ):
    class BM_Guest_User {
        public $id;

        public $secret;

        public $name;

        public $email;

        public $ip;

        public $created_at;

        public $updated_at;
    }
endif;;
