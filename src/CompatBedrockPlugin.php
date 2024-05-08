<?php
namespace Presslabs\CompatBedrock;

use WP_CLI;
use Presslabs\CompatBedrock\Exceptions\ConstantAlreadyDefinedException;
use function Env\env;
use RuntimeException;


class CompatBedrockPlugin {
    function __construct() {
        WP_CLI::add_command( 'presslabs-setup', [$this, 'cli_configure'] );
    }

    private function ensure_symlink( string $src, string $dest ) {
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

    private function cli_ensure_copyfile( string $src, string $dest ) {
        $dest = realpath($dest);

        if (file_exists($dest)) {
            $dest_content_hash = hash_file('sha256', $dest);
            $src_content_hash = hash_file('sha256', $src);
            if ($dest_content_hash == $src_content_hash) {
                return true;
            }

            WP_CLI::confirm( "Warning: Your environments/production.php exists and differs from presslabs/production.php. Do you want to overwrite?" );
        }

        return copy( $src, $dest );
    }

    public function ensure_dropins() {
        $this->ensure_symlink( __DIR__ . '/dropins/object-cache.php', WP_CONTENT_DIR . '/object-cache.php' );
        $this->ensure_symlink( '/www/presslabs/dropins/advanced-cache.php', WP_CONTENT_DIR . '/advanced-cache.php' );
    }

    public function install() {
        $this->ensure_dropins();
    }

    public function cli_configure( $args, $assoc_args ) {
        if (WP_ENV == "production") {
            throw new RuntimeException("This command should not be ran in a production environment but in development mode.");
        }

        $success = $this->cli_ensure_copyfile( __DIR__ . '/dropins/production.php', WP_CONTENT_DIR . '/../../config/environments/production.php' );

        if (!$success) {
            WP_CLI::error( "Failed to copy file!", $exit = true );
        }

        $this->ensure_dropins();

        WP_CLI::success( "Presslabs environment config is ok!" );
    }
}
