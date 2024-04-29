<?php

namespace Presslabs\CompatBedrock;

class CompatBedrockPlugin {

    private function pl_ensure_dropin( $dropin ) {
        $dropin_file = __DIR__ . "/$dropin";
        $src = realpath( $dropin_file );
        if ( false === $src ) {
            trigger_error( sprintf( 'Cannot find %s drop-in.', $dropin ), E_USER_ERROR );
        }

        $dest = WP_CONTENT_DIR . "/$dropin";

        $current_link_target = @readlink( $dest );

        // Check if there's already a symlink in place
        if ( $current_link_target == $src ) {
            return;
        }

        if ( file_exists( $dest ) ) {
            unlink( $dest );
        }

        if ( false === symlink( $src,  $dest ) ) {
            trigger_error( sprintf( 'Cannot install %s drop-in.', $dropin ), E_USER_ERROR );
        }

        return;
    }

    public function install() {
        $this->pl_ensure_dropin( 'object-cache.php' );
    }
}
