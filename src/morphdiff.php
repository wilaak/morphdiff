<?php

declare(strict_types=1);

namespace morphdiff;

final class Tag
{
    public const string NAME = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-';

    public const array VOID = [
        'area' => 1,
        'base' => 1,
        'br' => 1,
        'col' => 1,
        'embed' => 1,
        'hr' => 1,
        'img' => 1,
        'input' => 1,
        'link' => 1,
        'meta' => 1,
        'param' => 1,
        'source' => 1,
        'track' => 1,
        'wbr' => 1,
    ];

    public const array RAW = ['script' => 1, 'style' => 1, 'textarea' => 1];
}

final class V
{
    public const int HTML  = 0;

    public const int SEL   = 1;

    public const int START = 2;

    public const int END   = 3;
}

function view_new(string $html): array
{
    $v = [$html, [], [], []];
    scan($html, $v[V::SEL], $v[V::START], $v[V::END]);
    return $v;
}

function view_update(array &$v, string $html): array
{
    if ($html === $v[V::HTML]) {
        return [];
    }

    $ops = fast_path($v, $html);
    if ($ops !== null) {
        return $ops;
    }

    $sel = [];
    $start = [];
    $end = [];
    scan($html, $sel, $start, $end);
    $ops = diff($v[V::HTML], $v[V::SEL], $v[V::START], $v[V::END], $html, $sel, $start, $end);

    $v[V::HTML]  = $html;
    $v[V::SEL]   = $sel;
    $v[V::START] = $start;
    $v[V::END]   = $end;
    return $ops;
}

function compare(string $old, string $new): array
{
    $os = [];
    $ost = [];
    $oe = [];
    scan($old, $os, $ost, $oe);
    $ns = [];
    $nst = [];
    $ne = [];
    scan($new, $ns, $nst, $ne);
    return diff($old, $os, $ost, $oe, $new, $ns, $nst, $ne);
}

function scan(string $html, array &$sel, array &$start, array &$end): void
{
    $n = \strlen($html);

    $s_tag   = [];

    $s_start = [];

    $s_path  = [];

    $s_count = [];

    $depth  = 0;

    $i = 0;
    while (($lt = \strpos($html, '<', $i)) !== false) {
        $i = $lt;
        if ($i + 1 >= $n) {
            break;
        }
        $c = $html[$i + 1];

        if ($c === '/') {
            $ns   = $i + 2;
            $name = \strtolower(\substr($html, $ns, \strspn($html, Tag::NAME, $ns)));
            $gt   = \strpos($html, '>', $i);
            $close = $gt === false ? $n : $gt + 1;

            for ($d = $depth - 1; $d >= 0; $d--) {
                if ($s_tag[$d] === $name) {
                    $sel[]   = $s_path[$d];
                    $start[] = $s_start[$d];
                    $end[]   = $close;
                    if ($d === $depth - 1) {
                        \array_pop($s_tag);
                        \array_pop($s_start);
                        \array_pop($s_path);
                        \array_pop($s_count);
                    } else {
                        \array_splice($s_tag, $d, 1);
                        \array_splice($s_start, $d, 1);
                        \array_splice($s_path, $d, 1);
                        \array_splice($s_count, $d, 1);
                    }
                    $depth--;
                    break;
                }
            }
            $i = $close;
            continue;
        }

        if ($c === '!') {
            if (\substr($html, $i + 2, 2) === '--') {
                $e = \strpos($html, '-->', $i + 4);
                $i = $e === false ? $n : $e + 3;
            } else {
                $gt = \strpos($html, '>', $i);
                $i  = $gt === false ? $n : $gt + 1;
            }
            continue;
        }

        if (($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z')) {
            $ns   = $i + 1;
            $tlen = \strspn($html, Tag::NAME, $ns);
            $name = \strtolower(\substr($html, $ns, $tlen));
            $ne   = $ns + $tlen;
            $gt   = \strpos($html, '>', $i);
            if ($gt === false) {
                $gt = $n - 1;
            }
            $tag_end    = $gt + 1;
            $self_close = $gt > 0 && $html[$gt - 1] === '/';
            $id        = $gt > $ne ? extract_id($html, $ne, $gt - $ne) : null;

            if ($self_close || isset(Tag::VOID[$name])) {
                if ($depth > 0) {
                    $s_count[$depth - 1][$name] = ($s_count[$depth - 1][$name] ?? 0) + 1;
                }
                if ($id !== null) {
                    $sel[]   = '#' . $id;
                    $start[] = $i;
                    $end[]   = $tag_end;
                }
                $i = $tag_end;
                continue;
            }

            if ($id !== null) {
                if ($depth > 0) {
                    $s_count[$depth - 1][$name] = ($s_count[$depth - 1][$name] ?? 0) + 1;
                }
                $path = '#' . $id;
            } elseif ($depth > 0) {
                $nth = ($s_count[$depth - 1][$name] ?? 0) + 1;
                $s_count[$depth - 1][$name] = $nth;
                $path = $s_path[$depth - 1] . ' > ' . $name;
                if ($nth > 1) {
                    $path .= ':nth-of-type(' . $nth . ')';
                }
            } else {
                $path = $name;
            }

            if (isset(Tag::RAW[$name])) {
                $close   = raw_end($html, $n, $tag_end, $name);
                $sel[]   = $path;
                $start[] = $i;
                $end[]   = $close;
                $i = $close;
                continue;
            }

            $s_tag[$depth]   = $name;
            $s_start[$depth] = $i;
            $s_path[$depth]  = $path;
            $s_count[$depth] = [];
            $depth++;
            $i = $tag_end;
            continue;
        }

        $i++;
    }
}

function extract_id(string $html, int $off, int $len): ?string
{
    $attrs = \substr($html, $off, $len);
    if (\stripos($attrs, 'id') === false) {
        return null;
    }
    if (!\preg_match('/(?:^|\s)id\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>\/]+))/i', $attrs, $m)) {
        return null;
    }
    $v = ($m[1] ?? '') . ($m[2] ?? '') . ($m[3] ?? '');
    return $v === '' ? null : $v;
}

function raw_end(string $html, int $n, int $from, string $name): int
{
    $needle = '</' . $name;
    $nlen   = \strlen($needle);
    $i      = $from;
    while (($lt = \stripos($html, $needle, $i)) !== false) {
        $after = $lt + $nlen;
        $ch    = $after < $n ? $html[$after] : '>';

        if (($ch >= 'a' && $ch <= 'z') || ($ch >= 'A' && $ch <= 'Z')
            || ($ch >= '0' && $ch <= '9') || $ch === '-'
        ) {
            $i = $after;
            continue;
        }
        $gt = \strpos($html, '>', $after);
        return $gt === false ? $n : $gt + 1;
    }
    return $n;
}

function fast_path(array &$v, string $new): ?array
{
    $old   = $v[V::HTML];
    $old_n  = \strlen($old);
    $new_n  = \strlen($new);
    $lo    = common_prefix($old, $new);
    $hi_old = $old_n - common_suffix($old, $new, $lo);
    $hi_new = $new_n - ($old_n - $hi_old);

    if ($lo > $hi_old || $lo > $hi_new) {
        return null;
    }

    $o = \strpos($old, '<', $lo);
    $nn = \strpos($new, '<', $lo);
    if (($o !== false && $o < $hi_old) || ($nn !== false && $nn < $hi_new)) {
        return null;
    }

    $starts = &$v[V::START];
    $ends   = &$v[V::END];
    $count  = \count($starts);
    $delta  = $new_n - $old_n;

    $best = -1;
    $best_start = -1;
    for ($k = 0; $k < $count; $k++) {
        $st = (int) $starts[$k];
        $en = (int) $ends[$k];
        if ($st <= $lo && $en >= $hi_old && $st > $best_start) {
            $best = $k;
            $best_start = $st;
        }
        if ($st >= $hi_old) {
            $starts[$k] = $st + $delta;
        }
        if ($en >= $hi_old) {
            $ends[$k] = $en + $delta;
        }
    }

    $v[V::HTML] = $new;

    if ($best < 0) {
        return [];
    }

    return [[
        'selector' => $v[V::SEL][$best],
        'html'     => \substr($new, $best_start, (int) $ends[$best] - $best_start),
    ]];
}

function diff(
    string $old,
    array $o_sel,
    array $o_start,
    array $o_end,
    string $new,
    array $n_sel,
    array $n_start,
    array $n_end,
): array {
    $new_n = \strlen($new);
    $lo   = common_prefix($old, $new);
    $hi   = $new_n - common_suffix($old, $new, $lo);

    $old_idx = [];
    $co = \count($o_sel);
    for ($k = 0; $k < $co; $k++) {
        $old_idx[$o_sel[$k]] = $k;
    }

    $c_start = [];
    $c_end = [];
    $c_sel = [];
    $c_html = [];
    $cn = \count($n_sel);
    for ($k = 0; $k < $cn; $k++) {
        $ns   = (int) $n_start[$k];
        $nend = (int) $n_end[$k];
        if ($nend <= $lo || $ns >= $hi) {
            continue;
        }
        $sel = $n_sel[$k];
        $oi  = $old_idx[$sel] ?? -1;
        if ($oi < 0) {
            continue;
        }
        $o_start_i = (int) $o_start[$oi];
        $len  = $nend - $ns;
        $html = \substr($new, $ns, $len);
        if ($len === (int) $o_end[$oi] - $o_start_i && $html === \substr($old, $o_start_i, $len)) {
            continue;
        }
        $c_start[] = $ns;
        $c_end[]   = $nend;
        $c_sel[]   = $sel;
        $c_html[]  = $html;
    }

    return prune($c_start, $c_end, $c_sel, $c_html);
}

function prune(array $start, array $end, array $sel, array $html): array
{
    $k = \count($start);
    if ($k === 0) {
        return [];
    }

    $order = \range(0, $k - 1);
    \usort($order, static fn($a, $b) => ($start[$a] <=> $start[$b]) ?: ($end[$b] <=> $end[$a]));

    $ops = [];
    for ($x = 0; $x < $k; $x++) {
        $i   = $order[$x];
        $a_lo = (int) $start[$i];
        $a_hi = (int) $end[$i];
        $ancestor = false;
        for ($j = 0; $j < $k; $j++) {
            if ($j === $i) {
                continue;
            }
            $b_lo = (int) $start[$j];
            $b_hi = (int) $end[$j];
            if ($a_lo <= $b_lo && $a_hi >= $b_hi && ($a_lo < $b_lo || $a_hi > $b_hi)) {
                $ancestor = true;
                break;
            }
        }
        if (!$ancestor) {
            $ops[] = ['selector' => $sel[$i], 'html' => $html[$i]];
        }
    }
    return $ops;
}

const PROBE = 8192;

function common_prefix(string $a, string $b): int
{
    $max = \min(\strlen($a), \strlen($b));
    $off = 0;
    while ($off < $max) {
        $len = \min(PROBE, $max - $off);
        $run = \strspn(\substr($a, $off, $len) ^ \substr($b, $off, $len), "\0");
        if ($run < $len) {
            return $off + $run;
        }
        $off += $len;
    }
    return $max;
}

function common_suffix(string $a, string $b, int $cap_prefix): int
{
    $max = \min(\strlen($a), \strlen($b)) - $cap_prefix;
    if ($max <= 0) {
        return 0;
    }
    $la  = \strlen($a);
    $lb  = \strlen($b);
    $got = 0;
    while ($got < $max) {
        $len  = \min(PROBE, $max - $got);
        $x    = \substr($a, $la - $got - $len, $len) ^ \substr($b, $lb - $got - $len, $len);
        $tail = $len - \strlen(\rtrim($x, "\0"));

        if ($tail < $len) {
            return $got + $tail;
        }
        $got += $len;
    }
    return $max;
}
