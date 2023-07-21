<?php
/**
 * Plugin Name: HM PR Times
 * Description: PR Times
 * Author: Human Made Limited
 * Author URI: https://humanmade.com
 * Version: 1.0.0
 * License: GPL2+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.txt
 *
 * @package HM
 */

namespace HM\PR_Times;

// Required utility functions.
require_once __DIR__ . '/inc/press-events.php';
require_once __DIR__ . '/inc/story.php';
require_once __DIR__ . '/inc/attachments.php';
require_once __DIR__ . '/inc/namespace.php';

bootstrap();
