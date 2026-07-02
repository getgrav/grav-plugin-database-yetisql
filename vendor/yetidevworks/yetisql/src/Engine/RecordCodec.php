<?php

declare(strict_types=1);

namespace YetiDevWorks\YetiSQL\Engine;

/**
 * Encodes/decodes a row (a list of values) using SQLite's serial-type record
 * format: a header of varint serial types preceded by the header length,
 * followed by the tightly packed value bytes.
 *
 * Serial types:
 *   0           NULL
 *   1..6        big-endian signed int of 1,2,3,4,6,8 bytes
 *   7           IEEE-754 64-bit float
 *   8           integer 0   (zero body bytes)
 *   9           integer 1   (zero body bytes)
 *   N>=12 even  BLOB of (N-12)/2 bytes
 *   N>=13 odd   TEXT of (N-13)/2 bytes
 *
 * Accepted PHP value types: null, int, float, string (TEXT), Blob (binary).
 *
 * The hot paths here are deliberately written as flat inline loops: this is
 * the per-row cost of every read and write, and in PHP the function call and
 * the throwaway [value, consumed] tuple cost more than the byte-twiddling
 * they wrap. Varint reads inline their single-byte fast path (serial types
 * and header lengths are almost always < 0x80) and fall back to
 * Varint::decode only for multi-byte forms.
 */
final class RecordCodec
{
    /** Body byte length for serial types 0..9 (7 is an 8-byte float). */
    private const BODY_LEN = [0, 1, 2, 3, 4, 6, 8, 8, 0, 0];

    /**
     * @param list<null|int|float|string|Blob> $values
     */
    public static function encode(array $values): string
    {
        $serials = '';
        $body = '';

        foreach ($values as $v) {
            if ($v === null) {
                $serials .= "\x00";
            } elseif (\is_int($v)) {
                if ($v === 0) {
                    $serials .= "\x08";
                } elseif ($v === 1) {
                    $serials .= "\x09";
                } elseif ($v >= -128 && $v <= 127) {
                    $serials .= "\x01";
                    $body .= \chr($v & 0xFF);
                } elseif ($v >= -32768 && $v <= 32767) {
                    $serials .= "\x02";
                    $body .= \chr(($v >> 8) & 0xFF) . \chr($v & 0xFF);
                } elseif ($v >= -8388608 && $v <= 8388607) {
                    $serials .= "\x03";
                    $body .= \chr(($v >> 16) & 0xFF) . \chr(($v >> 8) & 0xFF) . \chr($v & 0xFF);
                } elseif ($v >= -2147483648 && $v <= 2147483647) {
                    $serials .= "\x04";
                    $body .= \pack('N', $v & 0xFFFFFFFF);
                } elseif ($v >= -140737488355328 && $v <= 140737488355327) {
                    // 48-bit: take low 6 bytes of the 64-bit big-endian form.
                    $serials .= "\x05";
                    $body .= \substr(\pack('J', $v), 2);
                } else {
                    $serials .= "\x06";
                    $body .= \pack('J', $v);
                }
            } elseif (\is_float($v)) {
                $serials .= "\x07";
                $body .= \pack('E', $v);
            } elseif ($v instanceof Blob) {
                $t = 12 + \strlen($v->bytes) * 2;
                if ($t < 0x80) {
                    $serials .= \chr($t);
                } elseif ($t < 0x4000) {
                    $serials .= \chr(0x80 | ($t >> 7)) . \chr($t & 0x7F);
                } else {
                    $serials .= Varint::encode($t);
                }
                $body .= $v->bytes;
            } else {
                // TEXT
                $s = (string) $v;
                $t = 13 + \strlen($s) * 2;
                if ($t < 0x80) {
                    $serials .= \chr($t);
                } elseif ($t < 0x4000) {
                    $serials .= \chr(0x80 | ($t >> 7)) . \chr($t & 0x7F);
                } else {
                    $serials .= Varint::encode($t);
                }
                $body .= $s;
            }
        }

        // Header length is self-describing: it counts its own varint too.
        $headerBodyLen = \strlen($serials);
        if ($headerBodyLen < 0x7F) {
            // Overwhelmingly common: the whole header fits a single length byte.
            return \chr($headerBodyLen + 1) . $serials . $body;
        }
        $sizeOfLen = Varint::size($headerBodyLen + 1);
        // Re-derive if adding the length varint pushed the total into another byte.
        $headerLen = $headerBodyLen + $sizeOfLen;
        if (Varint::size($headerLen) !== $sizeOfLen) {
            $headerLen = $headerBodyLen + Varint::size($headerLen);
        }

        return Varint::encode($headerLen) . $serials . $body;
    }

    /**
     * @return list<null|int|float|string|Blob>
     */
    public static function decode(string $record): array
    {
        $b = \ord($record[0]);
        if ($b < 0x80) {
            $headerLen = $b;
            $p = 1;
        } else {
            [$headerLen, $p] = Varint::decode($record, 0);
        }

        $types = [];
        while ($p < $headerLen) {
            $b = \ord($record[$p]);
            if ($b < 0x80) {
                $types[] = $b;
                $p++;
            } else {
                [$t, $n] = Varint::decode($record, $p);
                $types[] = $t;
                $p += $n;
            }
        }

        $values = [];
        $off = $headerLen;
        foreach ($types as $t) {
            if ($t >= 13) {
                if (($t & 1) === 1) { // TEXT
                    $len = ($t - 13) >> 1;
                    $values[] = \substr($record, $off, $len);
                    $off += $len;
                } else { // BLOB
                    $len = ($t - 12) >> 1;
                    $values[] = new Blob(\substr($record, $off, $len));
                    $off += $len;
                }
            } elseif ($t === 0) {
                $values[] = null;
            } elseif ($t === 1) {
                $v = \ord($record[$off]);
                $values[] = $v >= 0x80 ? $v - 0x100 : $v;
                $off++;
            } elseif ($t === 8) {
                $values[] = 0;
            } elseif ($t === 9) {
                $values[] = 1;
            } elseif ($t === 2) {
                $v = (\ord($record[$off]) << 8) | \ord($record[$off + 1]);
                $values[] = $v >= 0x8000 ? $v - 0x10000 : $v;
                $off += 2;
            } elseif ($t === 3) {
                $v = (\ord($record[$off]) << 16) | (\ord($record[$off + 1]) << 8) | \ord($record[$off + 2]);
                $values[] = $v >= 0x800000 ? $v - 0x1000000 : $v;
                $off += 3;
            } elseif ($t === 4) {
                /** @var array{1:int} $u */
                $u = \unpack('N', $record, $off);
                $v = $u[1];
                $values[] = $v >= 0x80000000 ? $v - 0x100000000 : $v;
                $off += 4;
            } elseif ($t === 7) {
                /** @var array{1:float} $u */
                $u = \unpack('E', $record, $off);
                $values[] = $u[1];
                $off += 8;
            } elseif ($t === 6) {
                /** @var array{1:int} $u */
                $u = \unpack('J', $record, $off);
                $values[] = $u[1];
                $off += 8;
            } elseif ($t === 5) {
                /** @var array{1:int} $u */
                $u = \unpack('N', $record, $off + 2);
                $v = ((\ord($record[$off]) << 40) | (\ord($record[$off + 1]) << 32) | $u[1]);
                $values[] = $v >= 0x800000000000 ? $v - 0x1000000000000 : $v;
                $off += 6;
            } else { // 12 (empty BLOB) — 10/11 are reserved and never produced
                $values[] = $t === 12 ? new Blob('') : null;
            }
        }

        return $values;
    }

    /**
     * Decode only the column at $index, skipping the rest. Used by the VM's
     * lazy column access so a query never materializes columns it ignores.
     */
    public static function decodeColumn(string $record, int $index): null|int|float|string|Blob
    {
        $b = \ord($record[0]);
        if ($b < 0x80) {
            $headerLen = $b;
            $p = 1;
        } else {
            [$headerLen, $p] = Varint::decode($record, 0);
        }
        $body = $headerLen;
        $i = 0;
        while ($p < $headerLen) {
            $b = \ord($record[$p]);
            if ($b < 0x80) {
                $type = $b;
                $p++;
            } else {
                [$type, $n] = Varint::decode($record, $p);
                $p += $n;
            }
            if ($i === $index) {
                return self::decodeAt($record, $type, $body);
            }
            $body += $type >= 12 ? ($type - 12 - ($type & 1)) >> 1 : self::BODY_LEN[$type];
            $i++;
        }
        return null;
    }

    /**
     * Parse just the record header into a per-column [serialType, bodyOffset]
     * map, without decoding any values. Lets a caller decode only the columns it
     * actually reads (via decodeAt), one body seek each, instead of paying to
     * materialize the whole row.
     *
     * @return array<int,array{0:int,1:int}>
     */
    public static function columnOffsets(string $record): array
    {
        $b = \ord($record[0]);
        if ($b < 0x80) {
            $headerLen = $b;
            $p = 1;
        } else {
            [$headerLen, $p] = Varint::decode($record, 0);
        }
        $offsets = [];
        $body = $headerLen;
        $i = 0;
        while ($p < $headerLen) {
            $b = \ord($record[$p]);
            if ($b < 0x80) {
                $type = $b;
                $p++;
            } else {
                [$type, $n] = Varint::decode($record, $p);
                $p += $n;
            }
            $offsets[$i++] = [$type, $body];
            $body += $type >= 12 ? ($type - 12 - ($type & 1)) >> 1 : self::BODY_LEN[$type];
        }
        return $offsets;
    }

    /**
     * Decode a sparse set of column positions in one header pass.
     *
     * @param list<int> $positions
     * @return array<int,null|int|float|string|Blob>
     */
    public static function decodeColumns(string $record, array $positions): array
    {
        if ($positions === []) {
            return [];
        }
        $want = [];
        $max = -1;
        foreach ($positions as $pos) {
            $want[$pos] = true;
            if ($pos > $max) {
                $max = $pos;
            }
        }

        $b = \ord($record[0]);
        if ($b < 0x80) {
            $headerLen = $b;
            $p = 1;
        } else {
            [$headerLen, $p] = Varint::decode($record, 0);
        }
        $body = $headerLen;
        $i = 0;
        $values = [];
        while ($p < $headerLen && $i <= $max) {
            $b = \ord($record[$p]);
            if ($b < 0x80) {
                $type = $b;
                $p++;
            } else {
                [$type, $n] = Varint::decode($record, $p);
                $p += $n;
            }
            if (isset($want[$i])) {
                $values[$i] = self::decodeAt($record, $type, $body);
            }
            $body += $type >= 12 ? ($type - 12 - ($type & 1)) >> 1 : self::BODY_LEN[$type];
            $i++;
        }
        foreach ($want as $pos => $_) {
            $values[$pos] ??= null;
        }
        return $values;
    }

    /** Decode a single value given its serial type and body offset. */
    public static function decodeAt(string $record, int $type, int $bodyOff): null|int|float|string|Blob
    {
        if ($type >= 13) {
            if (($type & 1) === 1) { // TEXT
                return \substr($record, $bodyOff, ($type - 13) >> 1);
            }
            return new Blob(\substr($record, $bodyOff, ($type - 12) >> 1));
        }
        switch ($type) {
            case 0:
                return null;
            case 1:
                $v = \ord($record[$bodyOff]);
                return $v >= 0x80 ? $v - 0x100 : $v;
            case 8:
                return 0;
            case 9:
                return 1;
            case 2:
                $v = (\ord($record[$bodyOff]) << 8) | \ord($record[$bodyOff + 1]);
                return $v >= 0x8000 ? $v - 0x10000 : $v;
            case 3:
                $v = (\ord($record[$bodyOff]) << 16) | (\ord($record[$bodyOff + 1]) << 8) | \ord($record[$bodyOff + 2]);
                return $v >= 0x800000 ? $v - 0x1000000 : $v;
            case 4:
                /** @var array{1:int} $u */
                $u = \unpack('N', $record, $bodyOff);
                $v = $u[1];
                return $v >= 0x80000000 ? $v - 0x100000000 : $v;
            case 7:
                /** @var array{1:float} $u */
                $u = \unpack('E', $record, $bodyOff);
                return $u[1];
            case 6:
                /** @var array{1:int} $u */
                $u = \unpack('J', $record, $bodyOff);
                return $u[1];
            case 5:
                /** @var array{1:int} $u */
                $u = \unpack('N', $record, $bodyOff + 2);
                $v = (\ord($record[$bodyOff]) << 40) | (\ord($record[$bodyOff + 1]) << 32) | $u[1];
                return $v >= 0x800000000000 ? $v - 0x1000000000000 : $v;
            default: // 12 (empty BLOB) — 10/11 are reserved and never produced
                return $type === 12 ? new Blob('') : null;
        }
    }
}
