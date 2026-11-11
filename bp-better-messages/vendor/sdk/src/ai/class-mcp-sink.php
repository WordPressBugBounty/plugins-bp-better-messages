<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_AI_MCP_Sink' ) ) {
    final class WordPlus_SDK_AI_MCP_Sink implements \WordPlusSDK\Psr\Http\Message\StreamInterface
    {
        use \WordPlusSDK\GuzzleHttp\Psr7\StreamDecoratorTrait;

        private $stream;

        private $limit;

        private $wanted;

        private $events = false;

        private $taken = 0;

        private $over = false;

        private $found = null;

        private $line = '';

        private $data = array();

        public function __construct( $limit, $wanted )
        {
            $this->stream = \WordPlusSDK\GuzzleHttp\Psr7\Utils::streamFor( fopen( 'php://temp', 'w+b' ) );
            $this->limit  = (int) $limit;
            $this->wanted = $wanted;
        }

        public function events()
        {
            $this->events = true;
        }

        public function over()
        {
            return $this->over;
        }

        public function found()
        {
            return $this->found;
        }

        public function write( string $string ): int
        {
            if ( null !== $this->found ) {
                return 0;
            }

            $size = strlen( $string );

            if ( $this->taken + $size > $this->limit ) {
                $this->over = true;

                return 0;
            }

            $this->taken += $size;

            if ( $this->events ) {
                $this->read_events( $string );

                return null === $this->found ? $size : 0;
            }

            return $this->stream->write( $string );
        }

        private function read_events( $chunk )
        {
            $this->line .= str_replace( array( "\r\n", "\r" ), "\n", $chunk );

            while ( false !== ( $at = strpos( $this->line, "\n" ) ) ) {
                $line       = substr( $this->line, 0, $at );
                $this->line = (string) substr( $this->line, $at + 1 );

                if ( '' === $line ) {
                    $this->event();
                } elseif ( 0 === strpos( $line, 'data:' ) ) {
                    $this->data[] = ltrim( substr( $line, 5 ), ' ' );
                }

                if ( null !== $this->found ) {
                    return;
                }
            }
        }

        private function event()
        {
            $text       = implode( "\n", $this->data );
            $this->data = array();

            if ( '' === $text ) {
                return;
            }

            $message = json_decode( $text, true );

            if ( is_array( $message ) && array_key_exists( 'id', $message ) && $message['id'] === $this->wanted && ( isset( $message['result'] ) || isset( $message['error'] ) ) ) {
                $this->found = $message;
            }
        }

        public function finish()
        {
            if ( null === $this->found && $this->events ) {
                $this->read_events( "\n\n" );
            }
        }
    }

    final class WordPlus_SDK_AI_MCP_Older_Sink implements \WordPlusSDK\Psr\Http\Message\StreamInterface
    {
        use \WordPlusSDK\GuzzleHttp\Psr7\StreamDecoratorTrait;

        private $stream;

        private $read = '';

        public function __construct()
        {
            $this->stream = \WordPlusSDK\GuzzleHttp\Psr7\Utils::streamFor( fopen( 'php://temp', 'w+b' ) );
        }

        public function seen()
        {
            return false !== strpos( $this->read, 'event: endpoint' ) || false !== strpos( $this->read, 'event:endpoint' );
        }

        public function write( string $string ): int
        {
            $this->read .= substr( $string, 0, 2048 );

            return $this->seen() || strlen( $this->read ) > 2048 ? 0 : strlen( $string );
        }
    }
}
