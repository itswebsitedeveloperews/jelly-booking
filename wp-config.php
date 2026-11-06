<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the web site, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * Localized language
 * * ABSPATH
 *
 * @link https://wordpress.org/support/article/editing-wp-config-php/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'jelly_booking_local' );

/** Database username */
define( 'DB_USER', 'jdb_0ad06abf' );

/** Database password */
define( 'DB_PASSWORD', 'c4ca773235e14dc4a32025c308c5c2e2' );

/** Database hostname */
define( 'DB_HOST', 'localhost' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',          'ejI<k+FIejo+-f3LP|q-(z}F.r6-X[_Tp%EY:_{BkkHC^{)a5{gS36MR!@gGl3*u' );
define( 'SECURE_AUTH_KEY',   'z?d*>R)K0c{r%8]I/Hi2Aj=Fli;~j*?G+Rl$VNl+>4#CFfi.||pvv~8<k2iI_H[1' );
define( 'LOGGED_IN_KEY',     'iKO}kX-iTY)2Kh4W6O3.9|pTcBfP6eV$~nHnL:-Ym,vtU0_+&,Jf=1mNO??F|tpl' );
define( 'NONCE_KEY',         'ZlLwzq^*@2S47jz!*0p4i%hQ,F|DG$P]td1F$_e5=b8wDAV;CR&/c=s+=XvGq!$/' );
define( 'AUTH_SALT',         'h6?He|E-ABV+5DAcnn>.=|aZ_MKn|ovNW%h[H,2]tOhQ(g0Z/8xwU$05Kv3<sl~p' );
define( 'SECURE_AUTH_SALT',  ':seR>1w& 4+|Z,]7?wWyvg4y=pz%NMv}.(5UB%:<%~D`Hckm(g_-3P4Mgc2VI>qP' );
define( 'LOGGED_IN_SALT',    'x36`L)Q[>w7InSYw3=7;ST%.Z^A.X`{d;`[C~Bm2XB@s]Fz(U94d={=^o]b-<c8W' );
define( 'NONCE_SALT',        '*-<u7wE$Ph6L5Uyi..F$pD25]XnivaXQ/Aa*H)z|Qu2mqrdY<*=1<eor%$=Yp0C^' );
define( 'WP_CACHE_KEY_SALT', 'C+DOYTyRapq1i0NoNH&6vBuPymc,mVPc,*,U?AeWr3oFA3tc[[JSl>CQwZ2|A`D,' );


/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = 'wp_';


/* Add any custom values between this line and the "stop editing" line. */



/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://wordpress.org/support/article/debugging-in-wordpress/
 */
if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', false );
}

/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
