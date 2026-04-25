<?php

namespace Sabatier\CoreData;

use JetBrains\PhpStorm\ExpectedValues;
use Override;
use Sabatier\Foundation\EscapeSequenceColor;
use Sabatier\Foundation\EscapeSequenceTextAttribute;
use Sabatier\Foundation\Formatter;
use function Sabatier\Foundation\escape_sequence;

/** @internal */
final class SQLFormatter extends Formatter
{
    private static bool $initialized = false;
    /** @var string[] $reserved */
    private static array $reserved = ["ACCESSIBLE", "ACTION", "AGAINST", "AGGREGATE", "ALGORITHM", "ALL", "ALTER", "ANALYSE", "ANALYZE", "AS", "ASC", "AUTOCOMMIT", "AUTO_INCREMENT", "BACKUP", "BEGIN", "BETWEEN", "BINLOG", "BOTH", "CASCADE", "CASE", "CHANGE", "CHANGED", "CHARACTER SET", "CHARSET", "CHECK", "CHECKSUM", "COLLATE", "COLLATION", "COLUMN", "COLUMNS", "COMMENT", "COMMIT", "COMMITTED", "COMPRESSED", "CONCURRENT", "CONSTRAINT", "CONTAINS", "CONVERT", "CREATE", "CROSS", "CURRENT_TIMESTAMP", "DATABASE", "DATABASES", "DAY", "DAY_HOUR", "DAY_MINUTE", "DAY_SECOND", "DEFAULT", "DEFINER", "DELAYED", "DELETE", "DESC", "DESCRIBE", "DETERMINISTIC", "DISTINCT", "DISTINCTROW", "DIV", "DO", "DUMPFILE", "DUPLICATE", "DYNAMIC", "ELSE", "ENCLOSED", "END", "ENGINE", "ENGINE_TYPE", "ENGINES", "ESCAPE", "ESCAPED", "EVENTS", "EXEC", "EXECUTE", "EXISTS", "EXPLAIN", "EXTENDED", "FALSE", "FAST", "FIELDS", "FILE", "FIRST", "FIXED", "FLUSH", "FOR", "FORCE", "FOREIGN", "FULL", "FULLTEXT", "FUNCTION", "GLOBAL", "GRANT", "GRANTS", "GROUP_CONCAT", "HEAP", "HIGH_PRIORITY", "HOSTS", "HOUR", "HOUR_MINUTE", "HOUR_SECOND", "IDENTIFIED", "IF", "IFNULL", "IGNORE", "IN", "INDEX", "INDEXES", "INFILE", "INSERT", "INSERT_ID", "INSERT_METHOD", "INTERVAL", "INTO", "INVOKER", "IS", "ISOLATION", "KEY", "KEYS", "KILL", "LAST_INSERT_ID", "LEADING", "LEVEL", "LIKE", "LINEAR", "LINES", "LOAD", "LOCAL", "LOCK", "LOCKS", "LOGS", "LOW_PRIORITY", "MARIA", "MASTER", "MASTER_CONNECT_RETRY", "MASTER_HOST", "MASTER_LOG_FILE", "MATCH", "MAX_CONNECTIONS_PER_HOUR", "MAX_QUERIES_PER_HOUR", "MAX_ROWS", "MAX_UPDATES_PER_HOUR", "MAX_USER_CONNECTIONS", "MEDIUM", "MERGE", "MINUTE", "MINUTE_SECOND", "MIN_ROWS", "MODE", "MODIFY", "MONTH", "MRG_MYISAM", "MYISAM", "NAMES", "NATURAL", "NOT", "NOW()", "NULL", "OFFSET", "ON", "OPEN", "OPTIMIZE", "OPTION", "OPTIONALLY", "ON UPDATE", "ON DELETE", "OUTFILE", "PACK_KEYS", "PAGE", "PARTIAL", "PARTITION", "PARTITIONS", "PASSWORD", "PRIMARY", "PRIVILEGES", "PROCEDURE", "PROCESS", "PROCESSLIST", "PURGE", "QUICK", "RANGE", "RAID0", "RAID_CHUNKS", "RAID_CHUNKSIZE", "RAID_TYPE", "READ", "READ_ONLY", "READ_WRITE", "REFERENCES", "REGEXP", "RELOAD", "RENAME", "REPAIR", "REPEATABLE", "REPLACE", "REPLICATION", "RESET", "RESTORE", "RESTRICT", "RETURN", "RETURNS", "REVOKE", "RLIKE", "ROLLBACK", "ROW", "ROWS", "ROW_FORMAT", "SECOND", "SECURITY", "SEPARATOR", "SERIALIZABLE", "SESSION", "SHARE", "SHOW", "SHUTDOWN", "SLAVE", "SONAME", "SOUNDS", "SQL", "SQL_AUTO_IS_NULL", "SQL_BIG_RESULT", "SQL_BIG_SELECTS", "SQL_BIG_TABLES", "SQL_BUFFER_RESULT", "SQL_CALC_FOUND_ROWS", "SQL_LOG_BIN", "SQL_LOG_OFF", "SQL_LOG_UPDATE", "SQL_LOW_PRIORITY_UPDATES", "SQL_MAX_JOIN_SIZE", "SQL_QUOTE_SHOW_CREATE", "SQL_SAFE_UPDATES", "SQL_SELECT_LIMIT", "SQL_SLAVE_SKIP_COUNTER", "SQL_SMALL_RESULT", "SQL_WARNINGS", "SQL_CACHE", "SQL_NO_CACHE", "START", "STARTING", "STATUS", "STOP", "STORAGE", "STRAIGHT_JOIN", "STRING", "STRIPED", "SUPER", "TABLE", "TABLES", "TEMPORARY", "TERMINATED", "THEN", "TO", "TRAILING", "TRANSACTIONAL", "TRUE", "TRUNCATE", "TYPE", "TYPES", "UNCOMMITTED", "UNIQUE", "UNLOCK", "UNSIGNED", "USAGE", "USE", "USING", "VARIABLES", "VIEW", "WHEN", "WITH", "WORK", "WRITE", "YEAR_MONTH"];
    /** @var string[] $reservedToplevel */
    private static array $reservedToplevel = ["SELECT", "FROM", "WHERE", "SET", "ORDER BY", "GROUP BY", "LIMIT", "DROP", "VALUES", "UPDATE", "HAVING", "ADD", "AFTER", "ALTER TABLE", "DELETE FROM", "UNION ALL", "UNION", "EXCEPT", "INTERSECT"];
    /** @var string[] $reservedNewline */
    private static array $reservedNewline = ["LEFT OUTER JOIN", "RIGHT OUTER JOIN", "LEFT JOIN", "RIGHT JOIN", "OUTER JOIN", "INNER JOIN", "JOIN", "XOR", "OR", "AND"];
    /** @var string[] $functions */
    private static array $functions = ["ABS", "ACOS", "ADDDATE", "ADDTIME", "AES_DECRYPT", "AES_ENCRYPT", "AREA", "ASBINARY", "ASCII", "ASIN", "ASTEXT", "ATAN", "ATAN2", "AVG", "BDMPOLYFROMTEXT", "BDMPOLYFROMWKB", "BDPOLYFROMTEXT", "BDPOLYFROMWKB", "BENCHMARK", "BIN", "BIT_AND", "BIT_COUNT", "BIT_LENGTH", "BIT_OR", "BIT_XOR", "BOUNDARY", "BUFFER", "CAST", "CEIL", "CEILING", "CENTROID", "CHAR", "CHARACTER_LENGTH", "CHARSET", "CHAR_LENGTH", "COALESCE", "COERCIBILITY", "COLLATION", "COMPRESS", "CONCAT", "CONCAT_WS", "CONNECTION_ID", "CONTAINS", "CONV", "CONVERT", "CONVERT_TZ", "CONVEXHULL", "COS", "COT", "COUNT", "CRC32", "CROSSES", "CURDATE", "CURRENT_DATE", "CURRENT_TIME", "CURRENT_TIMESTAMP", "CURRENT_USER", "CURTIME", "DATABASE", "DATE", "DATEDIFF", "DATE_ADD", "DATE_DIFF", "DATE_FORMAT", "DATE_SUB", "DAY", "DAYNAME", "DAYOFMONTH", "DAYOFWEEK", "DAYOFYEAR", "DECODE", "DEFAULT", "DEGREES", "DES_DECRYPT", "DES_ENCRYPT", "DIFFERENCE", "DIMENSION", "DISJOINT", "DISTANCE", "ELT", "ENCODE", "ENCRYPT", "ENDPOINT", "ENVELOPE", "EQUALS", "EXP", "EXPORT_SET", "EXTERIORRING", "EXTRACT", "EXTRACTVALUE", "FIELD", "FIND_IN_SET", "FLOOR", "FORMAT", "FOUND_ROWS", "FROM_DAYS", "FROM_UNIXTIME", "GEOMCOLLFROMTEXT", "GEOMCOLLFROMWKB", "GEOMETRYCOLLECTION", "GEOMETRYCOLLECTIONFROMTEXT", "GEOMETRYCOLLECTIONFROMWKB", "GEOMETRYFROMTEXT", "GEOMETRYFROMWKB", "GEOMETRYN", "GEOMETRYTYPE", "GEOMFROMTEXT", "GEOMFROMWKB", "GET_FORMAT", "GET_LOCK", "GLENGTH", "GREATEST", "GROUP_CONCAT", "GROUP_UNIQUE_USERS", "HEX", "HOUR", "IF", "IFNULL", "INET_ATON", "INET_NTOA", "INSERT", "INSTR", "INTERIORRINGN", "INTERSECTION", "INTERSECTS", "INTERVAL", "ISCLOSED", "ISEMPTY", "ISNULL", "ISRING", "ISSIMPLE", "IS_FREE_LOCK", "IS_USED_LOCK", "LAST_DAY", "LAST_INSERT_ID", "LCASE", "LEAST", "LEFT", "LENGTH", "LINEFROMTEXT", "LINEFROMWKB", "LINESTRING", "LINESTRINGFROMTEXT", "LINESTRINGFROMWKB", "LN", "LOAD_FILE", "LOCALTIME", "LOCALTIMESTAMP", "LOCATE", "LOG", "LOG10", "LOG2", "LOWER", "LPAD", "LTRIM", "MAKEDATE", "MAKETIME", "MAKE_SET", "MASTER_POS_WAIT", "MAX", "MBRCONTAINS", "MBRDISJOINT", "MBREQUAL", "MBRINTERSECTS", "MBROVERLAPS", "MBRTOUCHES", "MBRWITHIN", "MD5", "MICROSECOND", "MID", "MIN", "MINUTE", "MLINEFROMTEXT", "MLINEFROMWKB", "MOD", "MONTH", "MONTHNAME", "MPOINTFROMTEXT", "MPOINTFROMWKB", "MPOLYFROMTEXT", "MPOLYFROMWKB", "MULTILINESTRING", "MULTILINESTRINGFROMTEXT", "MULTILINESTRINGFROMWKB", "MULTIPOINT", "MULTIPOINTFROMTEXT", "MULTIPOINTFROMWKB", "MULTIPOLYGON", "MULTIPOLYGONFROMTEXT", "MULTIPOLYGONFROMWKB", "NAME_CONST", "NULLIF", "NUMGEOMETRIES", "NUMINTERIORRINGS", "NUMPOINTS", "OCT", "OCTET_LENGTH", "OLD_PASSWORD", "ORD", "OVERLAPS", "PASSWORD", "PERIOD_ADD", "PERIOD_DIFF", "PI", "POINT", "POINTFROMTEXT", "POINTFROMWKB", "POINTN", "POINTONSURFACE", "POLYFROMTEXT", "POLYFROMWKB", "POLYGON", "POLYGONFROMTEXT", "POLYGONFROMWKB", "POSITION", "POW", "POWER", "QUARTER", "QUOTE", "RADIANS", "RAND", "RELATED", "RELEASE_LOCK", "REPEAT", "REPLACE", "REVERSE", "RIGHT", "ROUND", "ROW_COUNT", "RPAD", "RTRIM", "SCHEMA", "SECOND", "SEC_TO_TIME", "SESSION_USER", "SHA", "SHA1", "SIGN", "SIN", "SLEEP", "SOUNDEX", "SPACE", "SQRT", "SRID", "STARTPOINT", "STD", "STDDEV", "STDDEV_POP", "STDDEV_SAMP", "STRCMP", "STR_TO_DATE", "SUBDATE", "SUBSTR", "SUBSTRING", "SUBSTRING_INDEX", "SUBTIME", "SUM", "SYMDIFFERENCE", "SYSDATE", "SYSTEM_USER", "TAN", "TIME", "TIMEDIFF", "TIMESTAMP", "TIMESTAMPADD", "TIMESTAMPDIFF", "TIME_FORMAT", "TIME_TO_SEC", "TOUCHES", "TO_DAYS", "TRIM", "TRUNCATE", "UCASE", "UNCOMPRESS", "UNCOMPRESSED_LENGTH", "UNHEX", "UNIQUE_USERS", "UNIX_TIMESTAMP", "UPDATEXML", "UPPER", "USER", "UTC_DATE", "UTC_TIME", "UTC_TIMESTAMP", "UUID", "VARIANCE", "VAR_POP", "VAR_SAMP", "VERSION", "WEEK", "WEEKDAY", "WEEKOFYEAR", "WITHIN", "X", "Y", "YEAR", "YEARWEEK"];
    /** @var string[] $boundaries */
    private static array $boundaries = [",", ";", ":", ")", "(", ".", "=", "<", ">", "+", "-", "*", "/", "!", "^", "%", "|", "&", "#"];
    private static string $regexBoundaries;
    private static string $regexReserved;
    private static string $regexReservedNewline;
    private static string $regexReservedToplevel;
    private static string $regexFunction;
    /** @var array<string, SQLFormatterToken> $tokenCache */
    private static array $tokenCache = [];
    public static int $maxCacheSize = 15;
    public static string $indent = "    ";

    public function __construct(#[ExpectedValues(flagsFromClass: SQLFormatterStyle::class)] public int $style = SQLFormatterStyle::highlighted)
    {
    }

    public static function initialize(): void
    {
        if (!self::$initialized) {
            $transform = fn(string $e): string => preg_quote($e, "/");
            $map = array_combine(self::$reserved, array_map(strlen(...), self::$reserved));
            arsort($map);
            self::$reserved = array_keys($map);
            self::$regexBoundaries = "(" . implode("|", array_map($transform, self::$boundaries)) . ")";
            self::$regexReserved = "(" . implode("|", array_map($transform, self::$reserved)) . ")";
            self::$regexReservedToplevel = str_replace(" ", "\\s+", "(" . implode("|", array_map($transform, self::$reservedToplevel)) . ")");
            self::$regexReservedNewline = str_replace(" ", "\\s+", "(" . implode("|", array_map($transform, self::$reservedNewline)) . ")");
            self::$regexFunction = "(" . implode("|", array_map($transform, self::$functions)) . ")";
            self::$initialized = true;
        }
    }

    private function highlighted(SQLFormatterToken $token): string
    {
        return escape_sequence((string)$token->value, match ($token->type) {
            SQLFormatterTokenType::function => EscapeSequenceTextAttribute::italic,
            default => EscapeSequenceTextAttribute::normal
        }, match ($token->type) {
            SQLFormatterTokenType::quote => EscapeSequenceColor::green,
            SQLFormatterTokenType::boundary, SQLFormatterTokenType::function => EscapeSequenceColor::brightWhite,
            SQLFormatterTokenType::reserved, SQLFormatterTokenType::reservedTopLevel, SQLFormatterTokenType::reservedNewline => EscapeSequenceColor::yellow,
            SQLFormatterTokenType::comment, SQLFormatterTokenType::blockComment, SQLFormatterTokenType::variable => EscapeSequenceColor::brightBlack,
            SQLFormatterTokenType::number => EscapeSequenceColor::brightBlue,
            SQLFormatterTokenType::error => EscapeSequenceColor::red,
            SQLFormatterTokenType::column => EscapeSequenceColor::magenta,
            SQLFormatterTokenType::whitespace, SQLFormatterTokenType::word, SQLFormatterTokenType::backtickQuote => EscapeSequenceColor::white,
        });
    }

    private function quoted(string $string): ?string
    {
        return preg_match("/^(((`[^`]*(\$|`))+)|((\\[[^]]*(\$|]))(][^]]*(\$|]))*)|((\"[^\"\\\\]*(?:\\\\.[^\"\\\\]*)*(\"|\$))+)|(('[^'\\\\]*(?:\\\\.[^'\\\\]*)*('|\$))+))/s", $string, $matches) ? $matches[1] : null;
    }

    private function token(string $string, ?SQLFormatterToken $previous = null): SQLFormatterToken
    {
        self::initialize();
        if (preg_match("/^\s+/", $string, $matches)) {
            return new SQLFormatterToken(SQLFormatterTokenType::whitespace, $matches[0]);
        }
        if ($string[0] === "#" || (isset($string[1]) && ($string[0] === "-" && $string[1] === "-") || ($string[0] === "/" && $string[1] === "*"))) {
            if ($string[0] === "-" || $string[0] === "#") {
                $last = strpos($string, "\n");
                $type = SQLFormatterTokenType::comment;
            } else {
                $last = (int)strpos($string, "*/", 2) + 2;
                $type = SQLFormatterTokenType::blockComment;
            }
            if ($last === false) {
                $last = strlen($string);
            }
            return new SQLFormatterToken($type, substr($string, 0, $last));
        }
        if (in_array($string[0], ["\"", "'", "`", "["], true)) {
            return new SQLFormatterToken((($string[0] === "`" || $string[0] === "[") ? SQLFormatterTokenType::backtickQuote : SQLFormatterTokenType::quote), $this->quoted($string));
        }
        if (($string[0] === "@" || $string[0] === ":") && isset($string[1])) {
            $ret = new SQLFormatterToken(SQLFormatterTokenType::variable);
            if (in_array($string[1], ["\"", "'", "`"], true)) {
                /** @psalm-suppress PossiblyNullOperand */
                $ret->value = $string[0] . $this->quoted(substr($string, 1));
            } else {
                preg_match("/^(" . $string[0] . "[a-zA-Z\d._\$]+)/", $string, $matches);
                if ($matches) {
                    $ret->value = $matches[1];
                }
            }
            if ($ret->value !== null) {
                return $ret;
            }
        }
        if (preg_match("/^(\\d+(\\.\\d+)?|0x[\\da-fA-F]+|0b[01]+)(\$|\\s|\"'`|" . self::$regexBoundaries . ")/", $string, $matches)) {
            return new SQLFormatterToken(SQLFormatterTokenType::number, $matches[1]);
        }
        if (preg_match("/^(" . self::$regexBoundaries . ")/", $string, $matches)) {
            return new SQLFormatterToken(SQLFormatterTokenType::boundary, $matches[1]);
        }
        if (!$previous || $previous->value !== ".") {
            $upper = strtoupper($string);
            if (preg_match("/^(" . self::$regexReservedToplevel . ")(\$|\\s|" . self::$regexBoundaries . ")/", $upper, $matches)) {
                return new SQLFormatterToken(SQLFormatterTokenType::reservedTopLevel, substr($string, 0, strlen($matches[1])));
            }
            if (preg_match("/^(" . self::$regexReservedNewline . ")($|\s|" . self::$regexBoundaries . ")/", $upper, $matches)) {
                return new SQLFormatterToken(SQLFormatterTokenType::reservedNewline, substr($string, 0, strlen($matches[1])));
            }
            if (preg_match("/^(" . self::$regexReserved . ")($|\s|" . self::$regexBoundaries . ")/", $upper, $matches)) {
                return new SQLFormatterToken(SQLFormatterTokenType::reserved, substr($string, 0, strlen($matches[1])));
            }
        }
        $upper = strtoupper($string);
        if (preg_match("/^(" . self::$regexFunction . "[(]|\s|[)])/", $upper, $matches)) {
            return new SQLFormatterToken(SQLFormatterTokenType::function, substr($string, 0, strlen($matches[1]) - 1));
        }
        preg_match("/^(.*?)(\$|\\s|[\"'`]|" . self::$regexBoundaries . ")/", $string, $matches);
        if ($previous && $previous->value === ".") {
            return new SQLFormatterToken(SQLFormatterTokenType::column, $matches[1]);
        }
        return new SQLFormatterToken(SQLFormatterTokenType::word, $matches[1]);
    }

    /**
     * @param string $string
     * @return SQLFormatterToken[]
     */
    private function tokens(string $string): array
    {
        $token = null;
        $tokens = [];
        $oldStringLen = strlen($string) + 1;
        $currentLength = strlen($string);
        while ($currentLength) {
            if ($oldStringLen <= $currentLength) {
                $tokens[] = new SQLFormatterToken(SQLFormatterTokenType::error, $string);
                return $tokens;
            }
            $oldStringLen = $currentLength;
            $cacheKey = $currentLength >= self::$maxCacheSize ? substr($string, 0, self::$maxCacheSize) : false;
            if ($cacheKey && isset(self::$tokenCache[$cacheKey])) {
                $token = self::$tokenCache[$cacheKey];
                $tokenLength = strlen((string)$token->value);
            } else {
                $token = $this->token($string, $token);
                $tokenLength = strlen((string)$token->value);
                if ($cacheKey && $tokenLength < self::$maxCacheSize) {
                    self::$tokenCache[$cacheKey] = $token;
                }
            }
            $tokens[] = $token;
            $string = substr($string, $tokenLength);
            $currentLength -= $tokenLength;
        }
        return $tokens;
    }

    private function highlight(string $string): string
    {
        $return = "";
        $tokens = $this->tokens($string);
        foreach ($tokens as $token) {
            $return .= $this->highlighted($token);
        }
        return "$return\n";
    }

    private function format(string $string, bool $highlight): string
    {
        $return = "";
        $tab = "\t";
        $indentLevel = 0;
        $newline = false;
        $inlineParentheses = false;
        $increaseSpecialIndent = false;
        $increaseBlockIndent = false;
        $indentTypes = [];
        $inlineCount = 0;
        $inlineIndented = false;
        $clauseLimit = false;
        $originalTokens = $this->tokens($string);
        $tokens = [];
        foreach ($originalTokens as $i => $token) {
            if ($token->type !== SQLFormatterTokenType::whitespace) {
                $token->index = $i;
                $tokens[] = $token;
            }
        }
        foreach ($tokens as $i => $token) {
            $highlighted = $highlight ? $this->highlighted($token) : (string)$token->value;
            if ($increaseSpecialIndent) {
                $indentLevel++;
                $increaseSpecialIndent = false;
                array_unshift($indentTypes, "special");
            }
            if ($increaseBlockIndent) {
                $indentLevel++;
                $increaseBlockIndent = false;
                array_unshift($indentTypes, "block");
            }
            if ($newline) {
                $return .= "\n" . str_repeat($tab, $indentLevel);
                $newline = false;
                $isNewLine = true;
            } else {
                $isNewLine = false;
            }
            if ($token->type === SQLFormatterTokenType::comment || $token->type === SQLFormatterTokenType::blockComment) {
                if ($token->type === SQLFormatterTokenType::blockComment) {
                    $indent = str_repeat($tab, $indentLevel);
                    $return .= "\n$indent";
                    $highlighted = str_replace("\n", "\n$indent", $highlighted);
                }
                $return .= $highlighted;
                $newline = true;
                continue;
            }
            if ($inlineParentheses) {
                if ($token->value === ")") {
                    $return = rtrim($return, " ");
                    if ($inlineIndented) {
                        array_shift($indentTypes);
                        $indentLevel--;
                        $return .= "\n" . str_repeat($tab, $indentLevel);
                    }
                    $inlineParentheses = false;
                    $return .= $highlighted . " ";
                    continue;
                }
                if ($token->value === "," && $inlineCount >= 30) {
                    $inlineCount = 0;
                    $newline = true;
                }
                $inlineCount += strlen((string)$token->value);
            }
            if ($token->value === "(") {
                $length = 0;
                for ($j = 1; $j <= 250; $j++) {
                    if (!isset($tokens[$i + $j])) {
                        break;
                    }
                    $next = $tokens[$i + $j];
                    if ($next->value === ")") {
                        $inlineParentheses = true;
                        $inlineCount = 0;
                        $inlineIndented = false;
                        break;
                    }
                    if ($next->value === ";" || $next->value === "(") {
                        break;
                    }
                    if (in_array($next->type, [SQLFormatterTokenType::reservedTopLevel, SQLFormatterTokenType::reservedNewline, SQLFormatterTokenType::comment, SQLFormatterTokenType::blockComment], true)) {
                        break;
                    }
                    $length += strlen((string)$next->value);
                }
                if ($inlineParentheses && $length > 30) {
                    $increaseBlockIndent = true;
                    $inlineIndented = true;
                    $newline = true;
                }
                if (isset($originalTokens[$token->index - 1]) && $originalTokens[$token->index - 1]->type !== SQLFormatterTokenType::whitespace) {
                    $return = rtrim($return, " ");
                }
                if (!$inlineParentheses) {
                    $increaseBlockIndent = true;
                    $newline = true;
                }
            } elseif ($token->value === ")") {
                $return = rtrim($return, " ");
                $indentLevel--;
                while ($j = array_shift($indentTypes)) {
                    if ($j === "special") {
                        $indentLevel--;
                    } else {
                        break;
                    }
                }
                if ($indentLevel < 0) {
                    $indentLevel = 0;
                    if ($highlight) {
                        $return .= "\n" . $this->highlighted($token);
                        continue;
                    }
                }
                if (!$isNewLine) {
                    $return .= "\n" . str_repeat($tab, $indentLevel);
                }
            } elseif ($token->type === SQLFormatterTokenType::reservedTopLevel) {
                $increaseSpecialIndent = true;
                reset($indentTypes);
                if (current($indentTypes) === "special") {
                    $indentLevel--;
                    array_shift($indentTypes);
                }
                $newline = true;
                if (!$isNewLine) {
                    $return .= "\n" . str_repeat($tab, $indentLevel);
                } else {
                    $return = rtrim($return, $tab) . str_repeat($tab, $indentLevel);
                }
                if (str_contains((string)$token->value, " ") || str_contains((string)$token->value, "\n") || str_contains((string)$token->value, "\t")) {
                    $highlighted = preg_replace("/\s+/", " ", $highlighted);
                }
                if ($token->value === "LIMIT" && !$inlineParentheses) {
                    $clauseLimit = true;
                }
            } elseif ($clauseLimit && $token->value !== "," && $token->type !== SQLFormatterTokenType::number && $token->type !== SQLFormatterTokenType::whitespace) {
                $clauseLimit = false;
            } elseif ($token->value === "," && !$inlineParentheses) {
                if ($clauseLimit) {
                    $newline = false;
                    $clauseLimit = false;
                } else {
                    $newline = true;
                }
            } elseif ($token->type === SQLFormatterTokenType::reservedNewline) {
                if (!$isNewLine) {
                    $return .= "\n" . str_repeat($tab, $indentLevel);
                }
                if (str_contains((string)$token->value, " ") || str_contains((string)$token->value, "\n") || str_contains((string)$token->value, "\t")) {
                    $highlighted = preg_replace("/\s+/", " ", $highlighted);
                }
            } elseif ($token->type === SQLFormatterTokenType::boundary) {
                /** @psalm-suppress InvalidArrayOffset */
                if (isset($tokens[$i - 1]) && $tokens[$i - 1]->type === SQLFormatterTokenType::boundary && (isset($originalTokens[$token->index - 1]) && $originalTokens[$token->index - 1]->type !== SQLFormatterTokenType::whitespace)) {
                    $return = rtrim($return, " ");
                }
            }
            if (in_array($token->value, [".", ",", ";"], true)) {
                $return = rtrim($return, " ");
            }
            $return .= "$highlighted ";
            if ($token->value === "(" || $token->value === ".") {
                $return = rtrim($return, " ");
            }
            if ($token->value === "-" && isset($tokens[$i + 1]) && $tokens[$i + 1]->type === SQLFormatterTokenType::number && isset($tokens[$i - 1])) {
                /** @psalm-suppress InvalidArrayOffset */
                $prev = $tokens[$i - 1]->type;
                if (!in_array($prev, [SQLFormatterTokenType::quote, SQLFormatterTokenType::backtickQuote, SQLFormatterTokenType::word, SQLFormatterTokenType::number], true)) {
                    $return = rtrim($return, " ");
                }
            }
        }
        if ($highlight && in_array("block", $indentTypes)) {
            $return .= escape_sequence("\nWARNING: unclosed parentheses or section", EscapeSequenceTextAttribute::normal, EscapeSequenceColor::brightRed);
        }
        $return = trim(str_replace("\t", self::$indent, $return));
        if ($highlight) {
            return "$return\n";
        }
        return $return;
    }

    #[Override]
    public function string(mixed $object): ?string
    {
        if (!is_string($object)) {
            return null;
        }
        $highlight = ($this->style & SQLFormatterStyle::highlighted) !== 0;
        if (!($this->style & SQLFormatterStyle::prettyPrinted)) {
            return $highlight ? $this->highlight($object) : $object;
        }
        return $this->format($object, $highlight);
    }
}
