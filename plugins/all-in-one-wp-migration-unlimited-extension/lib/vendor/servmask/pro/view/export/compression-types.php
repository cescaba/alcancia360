<?php
/**
 * Copyright (C) 2014-2025 ServMask Inc.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * Attribution: This code is part of the All-in-One WP Migration plugin, developed by
 *
 * ███████╗███████╗██████╗ ██╗   ██╗███╗   ███╗ █████╗ ███████╗██╗  ██╗
 * ██╔════╝██╔════╝██╔══██╗██║   ██║████╗ ████║██╔══██╗██╔════╝██║ ██╔╝
 * ███████╗█████╗  ██████╔╝██║   ██║██╔████╔██║███████║███████╗█████╔╝
 * ╚════██║██╔══╝  ██╔══██╗╚██╗ ██╔╝██║╚██╔╝██║██╔══██║╚════██║██╔═██╗
 * ███████║███████╗██║  ██║ ╚████╔╝ ██║ ╚═╝ ██║██║  ██║███████║██║  ██╗
 * ╚══════╝╚══════╝╚═╝  ╚═╝  ╚═══╝  ╚═╝     ╚═╝╚═╝  ╚═╝╚══════╝╚═╝  ╚═╝
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Kangaroos cannot jump here' );
}
?>

<?php if ( ai1wm_has_compression_type( 'gzip' ) || ai1wm_has_compression_type( 'bzip2' ) ) : ?>
	<li><strong><?php _e( 'Compression Options', AI1WM_PLUGIN_NAME ); ?></strong></li>
	<li>
		<label for="ai1wm-compression-type-none">
			<input type="radio" id="ai1wm-compression-type-none" name="options[compression_type]" value="" checked/>
			<?php _e( 'None (Fastest, largest file size)', AI1WM_PLUGIN_NAME ); ?>
		</label>
	</li>

	<?php if ( ai1wm_has_compression_type( 'gzip' ) ) : ?>
		<li>
			<label for="ai1wm-compression-type-gzip">
				<input type="radio" id="ai1wm-compression-type-gzip" name="options[compression_type]" value="gzip" />
				<?php _e( 'GZip (Fast, good compression)', AI1WM_PLUGIN_NAME ); ?>
				<small style="color: red;"><?php _e( 'new', AI1WM_PLUGIN_NAME ); ?></small>
			</label>
		</li>
	<?php endif; ?>

	<?php if ( ai1wm_has_compression_type( 'bzip2' ) ) : ?>
		<li>
			<label for="ai1wm-compression-type-bzip2">
				<input type="radio" id="ai1wm-compression-type-bzip2" name="options[compression_type]" value="bzip2" />
				<?php _e( 'BZip2 (Slower, better compression)', AI1WM_PLUGIN_NAME ); ?>
				<small style="color: red;"><?php _e( 'new', AI1WM_PLUGIN_NAME ); ?></small>
			</label>
		</li>
	<?php endif; ?>
<?php endif; ?>
