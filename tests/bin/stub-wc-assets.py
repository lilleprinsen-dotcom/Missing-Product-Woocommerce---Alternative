"""Test sites only: make WooCommerce (built from source without its JS build) stub missing admin asset registries."""
import sys

path = sys.argv[1]
src = open(path).read()
old = (
    "\t\t\t// could not find an asset file, throw an error.\n"
    "\t\t\tthrow new \\Exception( 'Could not find asset registry for ' . $script_path_name );"
)
new = (
    "\t\t\twp_mkdir_p( $script_asset_path );\n"
    "\t\t\tfile_put_contents( $script_asset_path . $script_nonmin_filename, \"<?php return array( 'dependencies' => array(), 'version' => 'dev' );\" );\n"
    "\t\t\treturn $script_nonmin_filename;"
)
if old in src:
    open(path, 'w').write(src.replace(old, new))
