#!/usr/bin/env php
<?php
/**
 * wp-zip-check — check a WordPress plugin or theme ZIP before you install it.
 *
 *   php wp-zip-check.php package.zip [--expect=1.2.3] [--json] [--no-clamav]
 *
 * What it does:
 *   - SHA-256 / SHA-1 / MD5 of the archive
 *   - safe unpack to a temp folder (absolute paths and "../" are skipped, 2 GB limit)
 *   - file counts by type
 *   - ClamAV scan (clamscan) of every unpacked file
 *   - reads plugin/theme headers (Version, Requires at least, Tested up to, Requires PHP, WC/Elementor)
 *   - optional version check (--expect)
 *   - php -l on every PHP file
 *   - changelog entry for the declared version (readme.txt / changelog.txt / CHANGELOG.md)
 *   - deletes the temp folder
 *
 * Exit codes: 0 = all checks passed, 1 = a check failed, 2 = error.
 *
 * License: GPL-2.0-or-later
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 2 );
}

const WZC_VERSION = '1.0.0';

/* ---------- arguments ---------- */

$args = array( 'zip' => '', 'expect' => '', 'json' => false, 'clamav' => true );
foreach ( array_slice( $argv, 1 ) as $a ) {
	if ( '--json' === $a ) {
		$args['json'] = true;
	} elseif ( '--no-clamav' === $a ) {
		$args['clamav'] = false;
	} elseif ( 0 === strpos( $a, '--expect=' ) ) {
		$args['expect'] = substr( $a, 9 );
	} elseif ( '--version' === $a || '-v' === $a ) {
		echo 'wp-zip-check ' . WZC_VERSION . "\n";
		exit( 0 );
	} elseif ( '--help' === $a || '-h' === $a ) {
		$args['zip'] = '';
		break;
	} elseif ( '' === $args['zip'] ) {
		$args['zip'] = $a;
	}
}
if ( '' === $args['zip'] ) {
	fwrite( STDERR, "Usage: php wp-zip-check.php package.zip [--expect=1.2.3] [--json] [--no-clamav]\n" );
	exit( 2 );
}
if ( ! is_file( $args['zip'] ) ) {
	fwrite( STDERR, "File not found: {$args['zip']}\n" );
	exit( 2 );
}
if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "PHP zip extension is required (php-zip).\n" );
	exit( 2 );
}

/* ---------- helpers ---------- */

function wzc_exec( $cmd, &$out = null ) {
	$out  = array();
	$code = 0;
	exec( $cmd . ' 2>&1', $out, $code );
	return (int) $code;
}

function wzc_rrmdir( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $f ) {
		$f->isDir() && ! $f->isLink() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() );
	}
	@rmdir( $dir );
}

function wzc_size( $b ) {
	$u = array( 'B', 'KB', 'MB', 'GB' );
	$i = 0;
	while ( $b >= 1024 && $i < 3 ) {
		$b /= 1024;
		$i++;
	}
	return round( $b, $i ? 1 : 0 ) . ' ' . $u[ $i ];
}

function wzc_type_of( $name ) {
	$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
	$map = array(
		'PHP'          => array( 'php', 'phtml', 'inc' ),
		'JavaScript'   => array( 'js', 'mjs', 'cjs', 'jsx', 'ts', 'map' ),
		'CSS / SCSS'   => array( 'css', 'scss', 'sass', 'less' ),
		'Images'       => array( 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico', 'avif', 'bmp' ),
		'Fonts'        => array( 'woff', 'woff2', 'ttf', 'eot', 'otf' ),
		'Translations' => array( 'po', 'mo', 'pot', 'l10n' ),
		'Data & text'  => array( 'json', 'xml', 'txt', 'md', 'yml', 'yaml', 'csv', 'html', 'htm', 'twig' ),
		'Archives'     => array( 'zip' ),
	);
	foreach ( $map as $label => $exts ) {
		if ( in_array( $ext, $exts, true ) ) {
			return $label;
		}
	}
	return 'Other';
}

function wzc_safe_names( ZipArchive $z, &$bytes = 0 ) {
	$safe  = array();
	$bytes = 0;
	for ( $i = 0; $i < $z->numFiles; $i++ ) {
		$st   = $z->statIndex( $i );
		$name = (string) $st['name'];
		if ( '' === $name || 0 === strpos( $name, '/' ) || preg_match( '#(^|/)\.\.(/|$)#', $name ) || false !== strpos( $name, "\0" ) ) {
			continue;
		}
		$safe[] = $name;
		$bytes += (int) $st['size'];
	}
	return $safe;
}

function wzc_read_headers( $file ) {
	$keys = array(
		'plugin'      => 'Plugin Name',
		'theme'       => 'Theme Name',
		'template'    => 'Template',
		'version'     => 'Version',
		'requires'    => 'Requires at least',
		'tested'      => 'Tested up to',
		'php'         => 'Requires PHP',
		'wc_requires' => 'WC requires at least',
		'wc_tested'   => 'WC tested up to',
		'el_tested'   => 'Elementor tested up to',
		'license'     => 'License',
		'text_domain' => 'Text Domain',
	);
	$data = str_replace( "\r", "\n", (string) @file_get_contents( $file, false, null, 0, 8192 ) );
	$out  = array();
	foreach ( $keys as $k => $h ) {
		if ( preg_match( '/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote( $h, '/' ) . ':(.*)$/mi', $data, $m ) ) {
			$v = trim( preg_replace( '/\s*(?:\*\/|\?>).*/', '', $m[1] ) );
			if ( '' !== $v ) {
				$out[ $k ] = mb_substr( strip_tags( $v ), 0, 80 );
			}
		}
	}
	return $out;
}

function wzc_norm_ver( $v ) {
	$v = ltrim( strtolower( trim( (string) $v ) ), 'v' );
	if ( ! preg_match( '/^\d+(?:\.\d+)*/', $v, $m ) ) {
		return '';
	}
	$p = explode( '.', $m[0] );
	while ( count( $p ) < 3 ) {
		$p[] = '0';
	}
	return implode( '.', array_map( 'intval', $p ) );
}

function wzc_find_main( $dir ) {
	$found = array();
	$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	$it->setMaxDepth( 3 );
	foreach ( $it as $f ) {
		if ( ! $f->isFile() ) {
			continue;
		}
		$name = strtolower( $f->getFilename() );
		if ( 'style.css' !== $name && 'php' !== strtolower( $f->getExtension() ) ) {
			continue;
		}
		if ( preg_match( '#/(vendor|node_modules|languages|tests?)/#i', $f->getPathname() ) ) {
			continue;
		}
		$h = wzc_read_headers( $f->getPathname() );
		if ( 'style.css' === $name && ! empty( $h['theme'] ) && empty( $h['template'] ) ) {
			$found[] = array( 'kind' => 'theme', 'file' => $f->getPathname(), 'h' => $h, 'depth' => $it->getDepth() );
		} elseif ( 'style.css' !== $name && ! empty( $h['plugin'] ) ) {
			$found[] = array( 'kind' => 'plugin', 'file' => $f->getPathname(), 'h' => $h, 'depth' => $it->getDepth() );
		}
	}
	usort( $found, function ( $a, $b ) {
		return $a['depth'] <=> $b['depth'];
	} );
	return $found;
}

function wzc_changelog_lines( $txt, $nv ) {
	$items = array();
	$in    = false;
	foreach ( explode( "\n", $txt ) as $line ) {
		$t       = trim( $line );
		$is_head = strlen( $t ) < 80 && preg_match( '/(?:^|[^\d.])v?(\d+\.\d+(?:\.\d+)*)(?:[^\d.]|$)/i', $t, $hm ) && ! preg_match( '/^[\*\-•]\s/u', $t ) && preg_match( '/^(?:[=#*\[]|v?\d|version|release|ver\.)/i', $t );
		if ( $is_head ) {
			if ( $in ) {
				break;
			}
			if ( wzc_norm_ver( $hm[1] ) === $nv ) {
				$in = true;
			}
			continue;
		}
		if ( ! $in || '' === $t ) {
			continue;
		}
		$t = preg_replace( '/^[\*\-•+]+\s*/u', '', $t );
		$t = trim( strip_tags( str_replace( array( '**', '__', '`' ), '', $t ) ) );
		if ( '' === $t || preg_match( '/^=+$|^-+$/', $t ) ) {
			continue;
		}
		if ( strlen( $t ) < 40 && preg_match( '/^[(\[]?\s*(?:released?(?: on)?|release date|date)?\s*:?\s*(?:\d{4}[-\/.]\d{1,2}[-\/.]\d{1,2}|\d{1,2}[-\/.]\d{1,2}[-\/.]\d{2,4}|[a-z]{3,9}\.? \d{1,2},? \d{4}|\d{1,2} [a-z]{3,9}\.? \d{4})\s*[)\]]?$/i', $t ) ) {
			continue;
		}
		$items[] = mb_strlen( $t ) > 240 ? mb_substr( $t, 0, 237 ) . '…' : $t;
		if ( count( $items ) >= 8 ) {
			break;
		}
	}
	return $items;
}

function wzc_changelog( $dir, $ver ) {
	$nv = wzc_norm_ver( $ver );
	if ( '' === $nv ) {
		return array();
	}
	foreach ( array( 'readme.txt', 'README.txt', 'changelog.txt', 'CHANGELOG.txt', 'changelog.md', 'CHANGELOG.md', 'readme.md', 'README.md' ) as $n ) {
		$file = $dir . '/' . $n;
		if ( ! is_file( $file ) ) {
			continue;
		}
		$txt = str_replace( "\r", '', (string) @file_get_contents( $file, false, null, 0, 400000 ) );
		if ( preg_match( '/^==\s*Changelog\s*==\s*$/mi', $txt, $m, PREG_OFFSET_CAPTURE ) ) {
			$txt = substr( $txt, $m[0][1] + strlen( $m[0][0] ) );
		} elseif ( preg_match( '/readme/i', $n ) && ! preg_match( '/^#+\s*Changelog/mi', $txt ) ) {
			continue;
		}
		$items = wzc_changelog_lines( $txt, $nv );
		if ( $items ) {
			return $items;
		}
	}
	return array();
}

function wzc_php_lint( $dir ) {
	$n = 0;
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) ) as $f ) {
		if ( $f->isFile() && 'php' === strtolower( $f->getExtension() ) ) {
			$n++;
		}
	}
	if ( ! $n ) {
		return null;
	}
	$cmd  = 'cd ' . escapeshellarg( $dir ) . ' && find . -type f -iname "*.php" -print0 | xargs -0 -n1 -P4 sh -c \'"$0" -d display_errors=1 -l "$1"; exit 0\' ' . escapeshellarg( PHP_BINARY );
	wzc_exec( $cmd, $out );
	$bad = array();
	$ok  = 0;
	foreach ( $out as $line ) {
		$line = trim( $line );
		if ( preg_match( '/^Errors parsing (.+)$/', $line, $m ) ) {
			$bad[] = ltrim( $m[1], './' );
		} elseif ( 0 === strpos( $line, 'No syntax errors detected' ) ) {
			$ok++;
		}
	}
	if ( $ok + count( $bad ) < $n ) {
		return null; // incomplete output: no claim
	}
	return array( 'files' => $n, 'errors' => count( $bad ), 'bad' => array_slice( $bad, 0, 10 ), 'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION );
}

function wzc_clamav( $dir ) {
	wzc_exec( 'clamscan --version', $vo );
	$ver = trim( (string) ( $vo[0] ?? '' ) );
	if ( 0 !== strpos( $ver, 'ClamAV' ) ) {
		return array( 'error' => 'clamscan not found (install ClamAV or use --no-clamav)' );
	}
	$code    = wzc_exec( 'clamscan -r --stdout --max-filesize=200M --max-scansize=2000M ' . escapeshellarg( $dir ), $out );
	$found   = array();
	$summary = array();
	$in_sum  = false;
	foreach ( $out as $line ) {
		$line = str_replace( rtrim( $dir, '/' ) . '/', '', $line );
		if ( false !== strpos( $line, 'SCAN SUMMARY' ) ) {
			$in_sum = true;
			continue;
		}
		if ( $in_sum && preg_match( '/^([A-Za-z ]+):\s*(.*)$/', $line, $m ) ) {
			$summary[ trim( $m[1] ) ] = trim( $m[2] );
		} elseif ( ! $in_sum && preg_match( '/^(.*): (.+) FOUND$/', $line, $m ) ) {
			$found[] = array( 'file' => $m[1], 'sig' => $m[2] );
		}
	}
	if ( 2 === $code || ! $summary ) {
		return array( 'error' => 'clamscan error: ' . implode( ' | ', array_slice( $out, -3 ) ) );
	}
	return array(
		'engine'   => $ver,
		'scanned'  => (int) ( $summary['Scanned files'] ?? 0 ),
		'infected' => (int) ( $summary['Infected files'] ?? count( $found ) ),
		'threats'  => $found,
		'time'     => preg_replace( '/\s*\(.*$/', '', (string) ( $summary['Time'] ?? '' ) ),
	);
}

/* ---------- run ---------- */

$zip  = realpath( $args['zip'] );
$base = rtrim( sys_get_temp_dir(), '/' ) . '/wp-zip-check-' . bin2hex( random_bytes( 4 ) );
$rep  = array(
	'tool'   => 'wp-zip-check ' . WZC_VERSION,
	'file'   => basename( $zip ),
	'bytes'  => filesize( $zip ),
	'sha256' => hash_file( 'sha256', $zip ),
	'sha1'   => hash_file( 'sha1', $zip ),
	'md5'    => hash_file( 'md5', $zip ),
);
$fail = false;

try {
	if ( ! mkdir( $base . '/x', 0700, true ) ) {
		throw new Exception( 'cannot create temp folder' );
	}
	$z = new ZipArchive();
	if ( true !== $z->open( $zip ) ) {
		throw new Exception( 'not a valid ZIP archive' );
	}
	$safe = wzc_safe_names( $z, $unpacked );
	if ( $unpacked > 2 * 1024 * 1024 * 1024 ) {
		throw new Exception( 'archive unpacks to more than 2 GB' );
	}
	$types = array();
	$files = 0;
	foreach ( $safe as $n ) {
		if ( '/' !== substr( $n, -1 ) ) {
			$files++;
			$t           = wzc_type_of( $n );
			$types[ $t ] = ( $types[ $t ] ?? 0 ) + 1;
		}
	}
	$rep['skipped'] = $z->numFiles - count( $safe );
	$z->extractTo( $base . '/x', $safe );
	$z->close();
	arsort( $types );
	$rep['files']    = $files;
	$rep['unpacked'] = $unpacked;
	$rep['types']    = $types;

	// Main plugin / theme (also inside nested ZIPs, e.g. "all files" bundles).
	$mains = wzc_find_main( $base . '/x' );
	if ( ! $mains ) {
		$i = 0;
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base . '/x', FilesystemIterator::SKIP_DOTS ) ) as $f ) {
			if ( $i >= 6 || ! $f->isFile() || 'zip' !== strtolower( $f->getExtension() ) ) {
				continue;
			}
			$nz = new ZipArchive();
			if ( true !== $nz->open( $f->getPathname() ) ) {
				continue;
			}
			$dst = $base . '/n' . $i++;
			mkdir( $dst, 0700, true );
			$nz->extractTo( $dst, wzc_safe_names( $nz ) );
			$nz->close();
			$mains = array_merge( $mains, wzc_find_main( $dst ) );
		}
	}

	if ( $mains ) {
		$main = $mains[0];
		$ev   = wzc_norm_ver( $args['expect'] );
		foreach ( $mains as $c ) {
			if ( '' !== $ev && wzc_norm_ver( $c['h']['version'] ?? '' ) === $ev ) {
				$main = $c;
				break;
			}
		}
		$dir = dirname( $main['file'] );
		$h   = $main['h'];
		foreach ( array( 'readme.txt', 'README.txt' ) as $rn ) {
			if ( is_file( $dir . '/' . $rn ) ) {
				$h += wzc_read_headers( $dir . '/' . $rn );
				break;
			}
		}
		$locales = array();
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) ) as $f ) {
			if ( $f->isFile() && 'mo' === strtolower( $f->getExtension() ) && preg_match( '/([a-z]{2,3}_[A-Z]{2}(?:_\w+)?|[a-z]{2,3})\.mo$/', $f->getFilename(), $lm ) ) {
				$locales[ $lm[1] ] = 1;
			}
		}
		$cl = array();
		foreach ( array_unique( array( $dir, dirname( $dir ), $base . '/x' ) ) as $cd ) {
			if ( 0 === strpos( $cd, $base ) && is_dir( $cd ) && ( $cl = wzc_changelog( $cd, $h['version'] ?? '' ) ) ) {
				break;
			}
		}
		$rep['package'] = array(
			'kind'      => $main['kind'],
			'name'      => $h[ $main['kind'] ] ?? '',
			'version'   => $h['version'] ?? '',
			'requires'  => $h['requires'] ?? '',
			'tested'    => $h['tested'] ?? '',
			'php'       => $h['php'] ?? '',
			'wc_tested' => $h['wc_tested'] ?? '',
			'el_tested' => $h['el_tested'] ?? '',
			'license'   => $h['license'] ?? '',
			'locales'   => count( $locales ),
			'changelog' => $cl,
		);
		if ( '' !== $ev ) {
			$rep['version_match'] = wzc_norm_ver( $h['version'] ?? '' ) === $ev;
			$fail                 = $fail || ! $rep['version_match'];
		}
		$rep['lint'] = wzc_php_lint( $dir );
		if ( $rep['lint'] && $rep['lint']['errors'] ) {
			$fail = true;
		}
	} else {
		$rep['package'] = array( 'error' => 'no plugin or theme header found' );
		$fail           = true;
	}

	if ( $args['clamav'] ) {
		$rep['clamav'] = wzc_clamav( $base . '/x' );
		if ( ! empty( $rep['clamav']['error'] ) || ! empty( $rep['clamav']['infected'] ) ) {
			$fail = true;
		}
	}
} catch ( Exception $e ) {
	$rep['error'] = $e->getMessage();
} finally {
	wzc_rrmdir( $base );
}

/* ---------- output ---------- */

if ( $args['json'] ) {
	echo json_encode( $rep, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
	exit( isset( $rep['error'] ) ? 2 : ( $fail ? 1 : 0 ) );
}

$tty = function_exists( 'posix_isatty' ) && posix_isatty( STDOUT );
$c   = function ( $s, $code ) use ( $tty ) {
	return $tty ? "\033[{$code}m{$s}\033[0m" : $s;
};
$ok  = $c( 'PASS', '32' );
$bad = $c( 'FAIL', '31' );
$na  = $c( 'SKIP', '33' );

echo "\n" . $c( 'wp-zip-check', '1' ) . '  ' . $rep['file'] . ' (' . wzc_size( $rep['bytes'] ) . ")\n\n";
echo "  SHA-256  {$rep['sha256']}\n  SHA-1    {$rep['sha1']}\n  MD5      {$rep['md5']}\n\n";

if ( isset( $rep['error'] ) ) {
	echo "  {$bad}  {$rep['error']}\n\n";
	exit( 2 );
}

echo '  Files    ' . number_format( $rep['files'] ) . ' (' . wzc_size( $rep['unpacked'] ) . ' unpacked)';
echo $rep['skipped'] ? ', ' . $rep['skipped'] . " unsafe paths skipped\n" : "\n";
$tl = array();
foreach ( $rep['types'] as $t => $n ) {
	$tl[] = "$t $n";
}
echo '           ' . implode( ' · ', $tl ) . "\n\n";

$p = $rep['package'];
if ( isset( $p['error'] ) ) {
	echo "  {$bad}  Package: {$p['error']}\n";
} else {
	echo '  ' . ucfirst( $p['kind'] ) . ': ' . $c( $p['name'], '1' ) . ' ' . $p['version'] . "\n";
	foreach ( array( 'requires' => 'Requires WordPress', 'tested' => 'Tested up to', 'php' => 'Requires PHP', 'wc_tested' => 'WooCommerce tested', 'el_tested' => 'Elementor tested', 'license' => 'License' ) as $k => $l ) {
		if ( '' !== $p[ $k ] ) {
			printf( "    %-20s %s\n", $l, $p[ $k ] );
		}
	}
	if ( $p['locales'] ) {
		printf( "    %-20s %d\n", 'Translations', $p['locales'] );
	}
	echo "\n";
}

if ( isset( $rep['version_match'] ) ) {
	echo '  ' . ( $rep['version_match'] ? $ok : $bad ) . '  Version ' . ( $rep['version_match'] ? 'matches ' : 'does not match ' ) . $args['expect'] . "\n";
}
if ( array_key_exists( 'lint', $rep ) ) {
	$l = $rep['lint'];
	if ( ! $l ) {
		echo "  {$na}  PHP lint (no PHP files or incomplete output)\n";
	} elseif ( $l['errors'] ) {
		echo "  {$bad}  PHP lint: {$l['errors']} of {$l['files']} files have syntax errors (PHP {$l['php']})\n";
		foreach ( $l['bad'] as $f ) {
			echo "          $f\n";
		}
	} else {
		echo "  {$ok}  PHP lint: {$l['files']} files, no syntax errors (PHP {$l['php']})\n";
	}
}
if ( ! $args['clamav'] ) {
	echo "  {$na}  ClamAV (--no-clamav)\n";
} elseif ( ! empty( $rep['clamav']['error'] ) ) {
	echo "  {$bad}  ClamAV: {$rep['clamav']['error']}\n";
} else {
	$cv = $rep['clamav'];
	if ( $cv['infected'] ) {
		echo "  {$bad}  ClamAV: {$cv['infected']} infected of {$cv['scanned']} files\n";
		foreach ( $cv['threats'] as $t ) {
			echo "          {$t['file']}: {$t['sig']}\n";
		}
	} else {
		echo "  {$ok}  ClamAV: {$cv['scanned']} files, nothing found ({$cv['engine']})\n";
	}
}

if ( ! empty( $p['changelog'] ) ) {
	echo "\n  What's new in {$p['version']}:\n";
	foreach ( $p['changelog'] as $line ) {
		echo "    - $line\n";
	}
}
echo "\n";
exit( $fail ? 1 : 0 );
