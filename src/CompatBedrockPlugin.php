<?php
namespace Presslabs\CompatBedrock;

use Presslabs\CompatBedrock\Exceptions\ConstantAlreadyDefinedException;
use function Env\env;


class CompatBedrockPlugin {

    private function pl_ensure_dropin( $dropin ) {
        $src = __DIR__ . "/dropins/$dropin";
        $dest = WP_CONTENT_DIR . "/$dropin";

        $this->ensure_symlink($src, $dest);

        return;
    }

    private function ensure_symlink( $src, $dest ) {
        $src_real = realpath( $src );
        if ( false === $src_real ) {
            trigger_error( sprintf( 'Cannot find %s drop-in.', $src ), E_USER_ERROR );
        }

        $current_link_target = @readlink( $dest );

        // Check if there's already a symlink in place
        if ( $current_link_target == $src_real ) {
            return;
        }

        if ( file_exists( $dest ) ) {
            unlink( $dest );
        }

        if ( false === symlink( $src_real,  $dest ) ) {
            trigger_error( sprintf( 'Cannot install %s drop-in in dest=%s.', $src_real, $dest ), E_USER_ERROR );
        }
    }

    public function install() {
        $this->pl_ensure_dropin( 'object-cache.php' );
        $this->ensure_symlink( '/www/presslabs/dropins/advanced-cache.php', WP_CONTENT_DIR . '/advanced-cache.php' );

        $this->ensure_symlink( __DIR__ . '/dropins/production.php', '/app/config/environments/production.php' );
    }
}
