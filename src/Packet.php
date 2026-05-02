<?php

namespace MeshCoreBridge;

/**
 * MeshCore companion protocol packet encoder/decoder.
 *
 * Serial frame wrapper (handled by SerialPort):
 *   app→radio  0x3C + uint16-LE length + payload
 *   radio→app  0x3E + uint16-LE length + payload
 *
 * Inside each payload the first byte is always the command or response code.
 */
class Packet
{
    // Commands (app → radio)
    const CMD_APP_START        = 1;
    const CMD_SEND_TXT_MSG     = 2;
    const CMD_SEND_CHANNEL_MSG = 3;
    const CMD_SEND_ADVERT      = 7;
    const CMD_SYNC_NEXT_MSG    = 10;
    const CMD_DEVICE_QUERY     = 22;

    // Response codes (radio → app, first byte of payload)
    const RESP_OK              = 0;
    const RESP_ERR             = 1;
    const RESP_CONTACT_START   = 2;
    const RESP_CONTACT         = 3;
    const RESP_CONTACT_END     = 4;
    const RESP_SELF_INFO       = 5;
    const RESP_SENT            = 6;
    const RESP_CONTACT_MSG     = 7;
    const RESP_CHANNEL_MSG     = 8;
    const RESP_NO_MORE_MSGS    = 10;
    const RESP_CONTACT_MSG_V3  = 16;
    const RESP_CHANNEL_MSG_V3  = 17;
    const RESP_DEVICE_INFO     = 13;
    const RESP_CHANNEL_INFO    = 18;

    // Push codes — unsolicited (radio → app, first byte >= 0x80)
    const PUSH_ADVERT          = 0x80;
    const PUSH_SEND_CONFIRMED  = 0x82;
    const PUSH_MSG_WAITING     = 0x83;
    const PUSH_LOG_DATA        = 0x88;
    const PUSH_NEW_ADVERT      = 0x8A;

    // Text types
    const TXT_PLAIN            = 0;
    const TXT_CLI_DATA         = 1;
    const TXT_SIGNED_PLAIN     = 2;

    // -------------------------------------------------------------------------
    // Encoders
    // -------------------------------------------------------------------------

    public static function deviceQuery(): string
    {
        return chr(self::CMD_DEVICE_QUERY)
             . chr(3); // supported companion protocol version
    }

    public static function appStart(string $appName = 'binktermphp-meshcorebridge'): string
    {
        return chr(self::CMD_APP_START)
             . chr(1)                 // appVer
             . str_repeat("\x00", 6) // reserved
             . $appName;
    }

    public static function syncNextMessage(): string
    {
        return chr(self::CMD_SYNC_NEXT_MSG);
    }

    /**
     * Send a self-advertisement.
     *
     * @param bool $flood  true = flood throughout the mesh; false = zero-hop (local only).
     */
    public static function sendAdvert(bool $flood): string
    {
        return chr(self::CMD_SEND_ADVERT)
             . chr($flood ? 1 : 0);
    }

    /**
     * Send a plain-text direct message to a contact.
     *
     * @param string $pubKeyPrefix6  Exactly 6 raw bytes of the recipient's public-key prefix.
     * @param string $text           UTF-8 message text.
     * @param int    $attempt        Retry counter; use 0 for a fresh send.
     */
    public static function sendTxtMsg(string $pubKeyPrefix6, string $text, int $attempt = 0): string
    {
        return chr(self::CMD_SEND_TXT_MSG)
             . chr(self::TXT_PLAIN)
             . chr($attempt)
             . pack('V', time())          // uint32 LE sender timestamp
             . substr($pubKeyPrefix6, 0, 6)
             . $text;
    }

    // -------------------------------------------------------------------------
    // Decoder
    // -------------------------------------------------------------------------

    /**
     * Decode a raw payload (everything after the frame header) into an array.
     *
     * Every returned array contains at least 'type' and 'code'.
     *
     * @return array<string,mixed>
     */
    public static function decode(string $payload): array
    {
        if ($payload === '') {
            return ['type' => 'empty', 'code' => -1];
        }

        $code = ord($payload[0]);
        $data = substr($payload, 1);

        switch ($code) {
            case self::RESP_CONTACT_START: return self::decodeContactsStart($data, $code);
            case self::RESP_CONTACT:       return self::decodeContactRecord($data, $code, 'contact');
            case self::RESP_CONTACT_END:   return self::decodeContactsEnd($data, $code);
            case self::RESP_CONTACT_MSG:   return self::decodeContactMsg($data, $code);
            case self::RESP_CHANNEL_MSG:   return self::decodeChannelMsg($data, $code);
            case self::RESP_CONTACT_MSG_V3:return self::decodeContactMsgV3($data, $code);
            case self::RESP_CHANNEL_MSG_V3:return self::decodeChannelMsgV3($data, $code);
            case self::RESP_SELF_INFO:     return self::decodeSelfInfo($data, $code);
            case self::RESP_DEVICE_INFO:   return self::decodeDeviceInfo($data, $code);
            case self::RESP_OK:            return ['type' => 'ok',             'code' => $code];
            case self::RESP_ERR:           return ['type' => 'err',            'code' => $code, 'detail' => bin2hex($data)];
            case self::RESP_SENT:          return ['type' => 'sent',           'code' => $code];
            case self::RESP_NO_MORE_MSGS:  return ['type' => 'no_more_msgs',   'code' => $code];
            case self::PUSH_MSG_WAITING:   return ['type' => 'msg_waiting',    'code' => $code];
            case self::PUSH_SEND_CONFIRMED:return ['type' => 'send_confirmed', 'code' => $code];
            case self::PUSH_ADVERT:        return ['type' => 'advert',         'code' => $code, 'raw' => bin2hex($data)];
            case self::PUSH_LOG_DATA:      return self::decodeLogData($data, $code);
            case self::PUSH_NEW_ADVERT:    return self::decodeContactRecord($data, $code, 'new_advert');
            default:                       return ['type' => 'unknown',        'code' => $code, 'raw' => bin2hex($data)];
        }
    }

    // -------------------------------------------------------------------------
    // Private decoders
    // -------------------------------------------------------------------------

    /** @return array<string,mixed> */
    private static function decodeContactMsg(string $data, int $code): array
    {
        // pubKeyPrefix(6) + pathLen(1) + txtType(1) + timestamp(4) + text(*)
        if (strlen($data) < 12) {
            return ['type' => 'contact_msg', 'code' => $code, 'error' => 'truncated'];
        }

        $pathRaw = ord($data[6]);
        return [
            'type'        => 'contact_msg',
            'code'        => $code,
            'pub_key_hex' => bin2hex(substr($data, 0, 6)),
            'hops'        => $pathRaw === 0xFF ? 0 : ($pathRaw & 0x3F),
            'is_direct'   => $pathRaw === 0xFF,
            'txt_type'    => ord($data[7]),
            'timestamp'   => unpack('V', substr($data, 8, 4))[1],
            'text'        => rtrim(substr($data, 12), "\x00"),
        ];
    }

    /** @return array<string,mixed> */
    private static function decodeChannelMsg(string $data, int $code): array
    {
        // channelIdx(1) + pathLen(1) + txtType(1) + timestamp(4) + text(*)
        if (strlen($data) < 7) {
            return ['type' => 'channel_msg', 'code' => $code, 'error' => 'truncated'];
        }

        return [
            'type'      => 'channel_msg',
            'code'      => $code,
            'channel'   => ord($data[0]),
            'hops'      => ord($data[1]) & 0x3F,
            'txt_type'  => ord($data[2]),
            'timestamp' => unpack('V', substr($data, 3, 4))[1],
            'text'      => rtrim(substr($data, 7), "\x00"),
        ];
    }

    /** @return array<string,mixed> */
    private static function decodeContactMsgV3(string $data, int $code): array
    {
        // snr(1) + reserved(2) + pubKeyPrefix(6) + pathLen(1) + txtType(1) + timestamp(4) + text(*)
        if (strlen($data) < 15) {
            return ['type' => 'contact_msg', 'code' => $code, 'error' => 'truncated'];
        }

        $pathRaw = ord($data[9]);
        return [
            'type'        => 'contact_msg',
            'code'        => $code,
            'snr'         => self::decodeSnr(ord($data[0])),
            'pub_key_hex' => bin2hex(substr($data, 3, 6)),
            'hops'        => $pathRaw === 0xFF ? 0 : ($pathRaw & 0x3F),
            'is_direct'   => $pathRaw === 0xFF,
            'txt_type'    => ord($data[10]),
            'timestamp'   => unpack('V', substr($data, 11, 4))[1],
            'text'        => rtrim(substr($data, 15), "\x00"),
        ];
    }

    /** @return array<string,mixed> */
    private static function decodeChannelMsgV3(string $data, int $code): array
    {
        // snr(1) + reserved(2) + channelIdx(1) + pathLen(1) + txtType(1) + timestamp(4) + text(*)
        if (strlen($data) < 10) {
            return ['type' => 'channel_msg', 'code' => $code, 'error' => 'truncated'];
        }

        return [
            'type'      => 'channel_msg',
            'code'      => $code,
            'snr'       => self::decodeSnr(ord($data[0])),
            'channel'   => ord($data[3]),
            'hops'      => ord($data[4]) & 0x3F,
            'txt_type'  => ord($data[5]),
            'timestamp' => unpack('V', substr($data, 6, 4))[1],
            'text'      => rtrim(substr($data, 10), "\x00"),
        ];
    }

    /** @return array<string,mixed> */
    private static function decodeSelfInfo(string $data, int $code): array
    {
        // type(1)+txPower(1)+maxTxPower(1)+pubKey(32)+advLat(4)+advLon(4)
        // +reserved(3)+manualAddContacts(1)+radioFreq(4)+radioBw(4)+radioSf(1)+radioCr(1)+name(*)
        if (strlen($data) < 57) {
            return ['type' => 'self_info', 'code' => $code, 'error' => 'truncated'];
        }

        return [
            'type'        => 'self_info',
            'code'        => $code,
            'adv_type'    => ord($data[0]),
            'tx_power'    => ord($data[1]),
            'pub_key_hex' => bin2hex(substr($data, 3, 32)),
            // MeshCore reports the center frequency in kHz.
            'freq_hz'     => unpack('V', substr($data, 47, 4))[1] * 1000,
            'bw_hz'       => unpack('V', substr($data, 51, 4))[1],
            'sf'          => ord($data[55]),
            'cr'          => ord($data[56]),
            'name'        => rtrim(substr($data, 57), "\x00"),
        ];
    }

    /** @return array<string,mixed> */
    private static function decodeDeviceInfo(string $data, int $code): array
    {
        // firmwareVer(1) + reserved(6) + buildDate(12, c-string) + model(*)
        if (strlen($data) < 19) {
            return ['type' => 'device_info', 'code' => $code, 'error' => 'truncated'];
        }

        return [
            'type'         => 'device_info',
            'code'         => $code,
            'firmware_ver' => ord($data[0]),
            'build_date'   => rtrim(substr($data, 7, 12), "\x00"),
            'model'        => rtrim(substr($data, 19), "\x00"),
        ];
    }

    /** @return array<string,mixed> */
    private static function decodeContactsStart(string $data, int $code): array
    {
        if (strlen($data) < 4) {
            return ['type' => 'contacts_start', 'code' => $code, 'error' => 'truncated'];
        }

        return [
            'type'  => 'contacts_start',
            'code'  => $code,
            'count' => unpack('V', substr($data, 0, 4))[1],
        ];
    }

    /** @return array<string,mixed> */
    private static function decodeContactsEnd(string $data, int $code): array
    {
        if (strlen($data) < 4) {
            return ['type' => 'contacts_end', 'code' => $code, 'error' => 'truncated'];
        }

        $lastmod = unpack('V', substr($data, 0, 4))[1];

        return [
            'type'            => 'contacts_end',
            'code'            => $code,
            'most_recent_lastmod' => $lastmod,
            'most_recent_lastmod_iso' => gmdate('c', $lastmod),
        ];
    }

    /** @return array<string,mixed> */
    private static function decodeContactRecord(string $data, int $code, string $type = 'contact'): array
    {
        if (strlen($data) < 147) {
            return ['type' => $type, 'code' => $code, 'error' => 'truncated', 'raw' => bin2hex($data)];
        }

        $pathLen = unpack('c', $data[34])[1];
        $pathBytes = substr($data, 35, 64);
        $lastAdvert = unpack('V', substr($data, 131, 4))[1];
        $lat = unpack('l', substr($data, 135, 4))[1];
        $lon = unpack('l', substr($data, 139, 4))[1];
        $lastmod = unpack('V', substr($data, 143, 4))[1];

        return [
            'type'              => $type,
            'code'              => $code,
            'pub_key_hex'       => bin2hex(substr($data, 0, 32)),
            'adv_type'          => ord($data[32]),
            'adv_type_name'     => self::advTypeName(ord($data[32])),
            'flags'             => ord($data[33]),
            'out_path_len'      => $pathLen,
            'out_path_hex'      => $pathLen > 0 ? bin2hex(substr($pathBytes, 0, min($pathLen, 64))) : '',
            'out_path_raw_hex'  => bin2hex($pathBytes),
            'adv_name'          => rtrim(substr($data, 99, 32), "\x00"),
            'last_advert'       => $lastAdvert,
            'last_advert_iso'   => gmdate('c', $lastAdvert),
            'adv_lat'           => $lat,
            'adv_lon'           => $lon,
            'adv_lat_deg'       => $lat / 1e6,
            'adv_lon_deg'       => $lon / 1e6,
            'lastmod'           => $lastmod,
            'lastmod_iso'       => gmdate('c', $lastmod),
            'raw'               => bin2hex($data),
        ];
    }

    /** @return array<string,mixed> */
    private static function decodeLogData(string $data, int $code): array
    {
        $packet = [
            'type'      => 'log_data',
            'code'      => $code,
            'raw'       => bin2hex($data),
            'raw_len'   => strlen($data),
            'ascii'     => self::asciiPreview($data),
        ];

        $advert = self::tryDecodeAdvertPayload($data);
        if ($advert !== null) {
            $packet['advert'] = $advert;
        }

        return $packet;
    }

    /** @return array<string,mixed>|null */
    private static function tryDecodeAdvertPayload(string $data): ?array
    {
        if (strlen($data) < 101) {
            return null;
        }

        $flags = ord($data[100]);
        if (($flags & 0x0F) === 0 || ($flags & 0x0F) > 0x04) {
            return null;
        }

        $offset = 101;
        $lat = null;
        $lon = null;

        if (($flags & 0x10) !== 0) {
            if (strlen($data) < $offset + 8) {
                return null;
            }
            $lat = unpack('l', substr($data, $offset, 4))[1];
            $lon = unpack('l', substr($data, $offset + 4, 4))[1];
            $offset += 8;
        }
        if (($flags & 0x20) !== 0) {
            if (strlen($data) < $offset + 2) {
                return null;
            }
            $offset += 2;
        }
        if (($flags & 0x40) !== 0) {
            if (strlen($data) < $offset + 2) {
                return null;
            }
            $offset += 2;
        }

        $name = '';
        if (($flags & 0x80) !== 0 && strlen($data) >= $offset) {
            $name = rtrim(substr($data, $offset), "\x00");
        }

        $timestamp = unpack('V', substr($data, 32, 4))[1];

        return [
            'pub_key_hex'      => bin2hex(substr($data, 0, 32)),
            'timestamp'        => $timestamp,
            'timestamp_iso'    => gmdate('c', $timestamp),
            'flags'            => $flags,
            'adv_type_name'    => self::advTypeName($flags & 0x0F),
            'has_location'     => ($flags & 0x10) !== 0,
            'has_name'         => ($flags & 0x80) !== 0,
            'lat'              => $lat,
            'lon'              => $lon,
            'lat_deg'          => $lat !== null ? $lat / 1e6 : null,
            'lon_deg'          => $lon !== null ? $lon / 1e6 : null,
            'name'             => $name,
        ];
    }

    private static function asciiPreview(string $data): string
    {
        $text = preg_replace('/[^\x20-\x7E]/', '.', $data) ?? '';
        return strlen($text) > 64 ? substr($text, 0, 64) . '...' : $text;
    }

    private static function advTypeName(int $type): string
    {
        return match ($type) {
            1 => 'chat',
            2 => 'repeater',
            3 => 'room_server',
            4 => 'sensor',
            default => 'unknown',
        };
    }

    private static function decodeSnr(int $byte): float
    {
        $signed = $byte < 128 ? $byte : $byte - 256;
        return $signed / 4.0;
    }
}
