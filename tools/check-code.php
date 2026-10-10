<?php
/**
 * Static checks of the shipped PHP (no WordPress needed), with PHP's own tokenizer so
 * strings and comments never count:
 *
 *   L7    every dynamic part of SQL passed to $wpdb->query/get_var/get_row/get_col/
 *         get_results — directly or as the format of $wpdb->prepare() — is a table name
 *         or a list of placeholders, never a value;
 *   PHP   no syntax or function newer than PHP 7.4 (Requires PHP: 7.4): nullsafe ?->,
 *         match, attributes, enums, readonly, named arguments, constructor promotion,
 *         first-class callables, mixed/static return types, PHP 8 functions WordPress
 *         6.2 does not polyfill.
 *
 *   php tools/check-code.php             checks parich-long-slug.php, uninstall.php, includes/
 *   php tools/check-code.php <files…>    checks those files
 *   php tools/check-code.php --selftest  must catch planted mistakes (exit 0 when it does)
 *
 * Exit 1 when something is reported, or when no file was checked.
 *
 * @package Parich_Long_Slug
 */

const PLS_SQL_METHODS = array('query', 'get_var', 'get_row', 'get_col', 'get_results');
/**
 * Variables that may appear in SQL text: table and column names (fixed in the code,
 * never input), placeholder lists, and formats built from those.
 */
const PLS_SQL_VARIABLES = '/^\$(wpdb|this|table|tables|urls|items|redirects|log|legacy|src|source|copy|prefix|in|in_keys|where|order|parent_sql|theme_sql|sql|insert|posts|placeholders|[a-z_]+_table|[a-z_]+_column)$/';
/** Properties of $wpdb that are table names. */
const PLS_WPDB_TABLES = '/^(posts|postmeta|terms|term_taxonomy|termmeta|term_relationships|options|prefix|users|usermeta)$/';
/** Calls that may appear in SQL text: they return table names or placeholder lists. */
const PLS_SQL_CALLS = '/^(items_table|urls_table|log_table|table|in_list|placeholders|implode|array_fill|count)$/';
const PLS_PHP8_FUNCTIONS = '/^(array_is_list|get_debug_type|fdiv|preg_last_error_msg|get_resource_id|memory_reset_peak_usage)$/';

function pls_significant(array $tokens, $i, $step)
{
	for ($j = $i + $step; isset($tokens[$j]); $j += $step) {
		if (!is_array($tokens[$j]) || !in_array($tokens[$j][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
			return $j;
		}
	}
	return null;
}

function pls_text($token)
{
	return is_array($token) ? $token[1] : $token;
}

/**
 * Tokens of the first argument of the call whose "(" is at $open.
 */
function pls_first_argument(array $tokens, $open)
{
	$depth = 0;
	$argument = array();
	for ($i = $open; isset($tokens[$i]); $i++) {
		$text = pls_text($tokens[$i]);
		if (in_array($text, array('(', '[', '{'), true) || (is_array($tokens[$i]) && in_array($tokens[$i][0], array(T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES), true))) {
			++$depth;
			if (1 === $depth && $i === $open) {
				continue;
			}
		} elseif (in_array($text, array(')', ']', '}'), true)) {
			--$depth;
			if (0 === $depth) {
				break;
			}
		} elseif (',' === $text && 1 === $depth) {
			break;
		}
		$argument[] = $tokens[$i];
	}
	return $argument;
}

/**
 * Dynamic parts of SQL text that are not table names or placeholder lists.
 */
function pls_sql_problems(array $argument)
{
	$problems = array();
	$count = count($argument);
	for ($i = 0; $i < $count; $i++) {
		$token = $argument[$i];
		if (!is_array($token)) {
			continue;
		}
		if (T_VARIABLE === $token[0]) {
			if (!preg_match(PLS_SQL_VARIABLES, $token[1])) {
				$problems[] = $token[1];
			} elseif ('$this' === $token[1]) {
				$arrow = pls_significant($argument, $i, 1);
				$name = null === $arrow ? null : pls_significant($argument, $arrow, 1);
				if (null === $name || 'table' !== pls_text($argument[$name])) {
					$problems[] = '$this->' . (null === $name ? '?' : pls_text($argument[$name]));
				}
			} elseif ('$wpdb' === $token[1]) {
				$arrow = pls_significant($argument, $i, 1);
				$name = null === $arrow ? null : pls_significant($argument, $arrow, 1);
				if (null === $name || !preg_match(PLS_WPDB_TABLES, pls_text($argument[$name]))) {
					$problems[] = '$wpdb->' . (null === $name ? '?' : pls_text($argument[$name]));
				}
			}
		} elseif (T_STRING === $token[0]) {
			$next = pls_significant($argument, $i, 1);
			$previous = pls_significant($argument, $i, -1);
			$is_call = null !== $next && '(' === pls_text($argument[$next]);
			$is_property = null !== $previous && in_array(pls_text($argument[$previous]), array('->', '::'), true);
			if ($is_call && !preg_match(PLS_SQL_CALLS, $token[1])) {
				$problems[] = $token[1] . '()';
			} elseif ($is_call) {
				// An allowed call returns a table name or placeholders: what it is given is not SQL.
				$depth = 0;
				for ($j = $next; $j < $count; $j++) {
					$text = pls_text($argument[$j]);
					$depth += '(' === $text ? 1 : (')' === $text ? -1 : 0);
					if (0 === $depth) {
						break;
					}
				}
				$i = $j;
			} elseif (!$is_call && $is_property && null !== $previous && '->' === pls_text($argument[$previous])) {
				$owner = pls_significant($argument, $previous, -1);
				if (null !== $owner && '$wpdb' === pls_text($argument[$owner]) && !preg_match(PLS_WPDB_TABLES, $token[1])) {
					$problems[] = '$wpdb->' . $token[1];
				}
			}
		}
	}
	return array_unique($problems);
}

/**
 * @return string[] Problems, "file:line message".
 */
function pls_check_file($file)
{
	$code = (string) file_get_contents($file);
	$tokens = token_get_all($code);
	$problems = array();
	$report = function ($line, $message) use (&$problems, $file) {
		$problems[] = "{$file}:{$line} {$message}";
	};
	$php8 = array(
		'T_NULLSAFE_OBJECT_OPERATOR' => 'nullsafe operator ?-> (PHP 8.0)',
		'T_MATCH' => 'match expression (PHP 8.0)',
		'T_ATTRIBUTE' => 'attribute #[...] (PHP 8.0)',
		'T_ENUM' => 'enum (PHP 8.1)',
		'T_READONLY' => 'readonly (PHP 8.1)',
	);
	foreach ($tokens as $i => $token) {
		if (!is_array($token)) {
			continue;
		}
		list($id, $text, $line) = $token;
		foreach ($php8 as $name => $message) {
			if (defined($name) && constant($name) === $id) {
				$report($line, $message);
			}
		}
		// $wpdb->query( … ) and friends.
		if (T_VARIABLE === $id && '$wpdb' === $text) {
			$arrow = pls_significant($tokens, $i, 1);
			$method = null === $arrow ? null : pls_significant($tokens, $arrow, 1);
			$open = null === $method ? null : pls_significant($tokens, $method, 1);
			if (null !== $open && '->' === pls_text($tokens[$arrow]) && in_array(pls_text($tokens[$method]), PLS_SQL_METHODS, true) && '(' === pls_text($tokens[$open])) {
				$argument = pls_first_argument($tokens, $open);
				$first = pls_significant(array_merge(array(null), $argument), 0, 1);
				$first = null === $first ? null : $first - 1;
				// The format of $wpdb->prepare( … ) is checked the same way; its other arguments are values.
				if (null !== $first && '$wpdb' === pls_text($argument[$first])) {
					$inner_arrow = pls_significant($argument, $first, 1);
					$inner = null === $inner_arrow ? null : pls_significant($argument, $inner_arrow, 1);
					if (null !== $inner && 'prepare' === pls_text($argument[$inner])) {
						$inner_open = pls_significant($argument, $inner, 1);
						$argument = pls_first_argument($argument, $inner_open);
					}
				}
				foreach (pls_sql_problems($argument) as $problem) {
					$report($line, "SQL built with {$problem} outside \$wpdb->prepare() (L7)");
				}
			}
		}
		if (T_STRING === $id) {
			$previous = pls_significant($tokens, $i, -1);
			$next = pls_significant($tokens, $i, 1);
			$before = null === $previous ? '' : pls_text($tokens[$previous]);
			$after = null === $next ? '' : pls_text($tokens[$next]);
			if ('(' === $after && preg_match(PLS_PHP8_FUNCTIONS, $text) && !in_array($before, array('->', '::', 'function'), true)) {
				$report($line, "{$text}() needs PHP 8 (not polyfilled by WordPress 6.2)");
			}
			// Named argument: "(name:" or ", name:" (not "::").
			if (in_array($before, array('(', ','), true) && ':' === $after) {
				$report($line, "named argument {$text}: (PHP 8.0)");
			}
		}
		// Constructor promotion: visibility inside a function's parameter list.
		if (T_FUNCTION === $id) {
			$depth = 0;
			for ($j = $i + 1; isset($tokens[$j]); $j++) {
				$text = pls_text($tokens[$j]);
				if ('(' === $text) {
					++$depth;
				} elseif (')' === $text) {
					--$depth;
					if (0 === $depth) {
						break;
					}
				} elseif ('{' === $text || ';' === $text) {
					break;
				} elseif ($depth > 0 && is_array($tokens[$j]) && in_array($tokens[$j][0], array(T_PUBLIC, T_PROTECTED, T_PRIVATE), true)) {
					$report($tokens[$j][2], 'constructor property promotion (PHP 8.0)');
				}
			}
		}
		// First-class callable: "(...)".
		if (T_ELLIPSIS === $id) {
			$next = pls_significant($tokens, $i, 1);
			if (null !== $next && ')' === pls_text($tokens[$next])) {
				$report($line, 'first-class callable syntax (PHP 8.1)');
			}
		}
		// mixed / static return types.
		if (in_array($id, array(T_STRING, T_STATIC), true) && in_array(strtolower($text), array('mixed', 'static', 'never'), true)) {
			$previous = pls_significant($tokens, $i, -1);
			$before = null === $previous ? null : pls_significant($tokens, $previous, -1);
			if (null !== $previous && ':' === pls_text($tokens[$previous]) && null !== $before && ')' === pls_text($tokens[$before])) {
				$report($line, "{$text} return type (PHP 8)");
			}
		}
	}
	return $problems;
}

function pls_check_files(array $files)
{
	$problems = array();
	foreach ($files as $file) {
		$problems = array_merge($problems, pls_check_file($file));
	}
	return $problems;
}

function pls_shipped_files($root)
{
	$files = array($root . '/parich-long-slug.php', $root . '/uninstall.php');
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/includes', FilesystemIterator::SKIP_DOTS));
	foreach ($iterator as $file) {
		if ('php' === $file->getExtension()) {
			$files[] = str_replace('\\', '/', $file->getPathname());
		}
	}
	sort($files);
	return $files;
}

$root = str_replace('\\', '/', dirname(__DIR__));
$args = array_slice($argv, 1);

if (array('--selftest') === $args) {
	$dir = sys_get_temp_dir() . '/pls-check-' . getmypid();
	@mkdir($dir);
	$cases = array(
		'value in SQL' => "<?php \$wpdb->query(\"DELETE FROM {\$wpdb->posts} WHERE ID = \$id\");",
		'concatenated value' => "<?php \$wpdb->get_var('SELECT post_name FROM ' . \$wpdb->posts . ' WHERE ID = ' . \$post_id);",
		'value in prepare format' => "<?php \$wpdb->get_results(\$wpdb->prepare(\"SELECT * FROM {\$table} WHERE post_name = '{\$slug}' AND ID = %d\", 5));",
		'call in SQL' => "<?php \$wpdb->query('DELETE FROM x WHERE a = ' . sanitize_text_field(\$_GET['a']));",
		'property of $this in SQL' => "<?php \$wpdb->get_var(\"SELECT 1 FROM t WHERE a = '{\$this->value}'\");",
		'nullsafe' => "<?php \$a = \$b?->c;",
		'match' => "<?php \$a = match (\$b) { 1 => 2, default => 3 };",
		'named argument' => "<?php foo(name: 1);",
		'promotion' => "<?php class A { public function __construct(private \$a) {} }",
		'first-class callable' => "<?php \$f = strlen(...);",
		'PHP 8 function' => "<?php \$x = array_is_list(\$y);",
	);
	$clean = "<?php \$wpdb->query(\$wpdb->prepare(\"UPDATE {\$wpdb->posts} SET post_name = %s WHERE ID IN (\" . implode(',', array_fill(0, count(\$ids), '%d')) . ')', \$args));\n\$x = \$a ? \$b : \$c; foo(\$a, \$b); \$t = 'note: (not code)';";
	$failed = 0;
	foreach ($cases as $label => $code) {
		file_put_contents("{$dir}/case.php", $code);
		$found = pls_check_file("{$dir}/case.php");
		echo ($found ? '  ok   ' : '  MISS ') . "{$label}" . ($found ? ' — ' . preg_replace('/^.*?:\d+ /', '', $found[0]) : '') . "\n";
		$failed += $found ? 0 : 1;
	}
	file_put_contents("{$dir}/clean.php", $clean);
	$found = pls_check_file("{$dir}/clean.php");
	echo ($found ? '  FALSE ALARM ' : '  ok   ') . 'prepared SQL with table names and placeholders, ternary, strings' . ($found ? ' — ' . implode('; ', $found) : '') . "\n";
	$failed += $found ? 1 : 0;
	@unlink("{$dir}/case.php");
	@unlink("{$dir}/clean.php");
	@rmdir($dir);
	echo $failed ? "Self-test FAILED ({$failed})\n" : 'Self-test: all ' . (count($cases) + 1) . " cases behave\n";
	exit($failed ? 1 : 0);
}

$files = $args ? $args : pls_shipped_files($root);
if (!$files) {
	fwrite(STDERR, "No files to check.\n");
	exit(1);
}
$problems = pls_check_files($files);
foreach ($problems as $problem) {
	echo $problem, "\n";
}
echo count($files) . ' files checked, ' . count($problems) . " problems\n";
exit($problems ? 1 : 0);
