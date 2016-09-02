<?php

namespace Smartmoney\Stellar;

class Account
{

    const TYPE_ANONYMOUS = 0;
    const TYPE_REGISTERED = 1;
    const TYPE_MERCHANT = 2;
    const TYPE_DISTRIBUTION = 3;
    const TYPE_SETTLEMENT = 4;
    const TYPE_EXCHANGE = 5;
    const TYPE_BANK = 6;

    private static $versionBytes = array(
        'accountId' => 0x30,
        'seed' => 0x90
    );

    /**
     * @param $accountId - account id in stellar
     * @param $host - horizon host
     * @param $port - horizon port
     * @param bool $asset_code - array|string|null - asset code|codes
     * @return array|bool
     */
    public static function getAccountBalances($accountId, $host, $port, $asset_code = false)
    {

        try {
            $host = trim($host, '/');

            $getLink = 'http://' . $host . ':' . $port . '/accounts/' . $accountId;
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_URL, $getLink);
            $result = json_decode(curl_exec($ch));

            if (isset($result) && !empty($result->account_id) && $result->account_id == $accountId && isset($result->balances)) {
                if (count($result->balances) == 0) {
                    return false;
                }

                $balances = [];

                if (!empty($asset_code)) {

                    if (is_string($asset_code)) {
                        foreach ($result->balances as $balance) {
                            if (!empty($balance->asset_code) && $balance->asset_code == $asset_code) {
                                $balances[$balance->asset_code] = $balance->balance;

                                return $balances;
                            }
                        }
                    }

                    if (is_array($asset_code)) {
                        foreach ($result->balances as $balance) {
                            if (!empty($balance->asset_code) && in_array($balance->asset_code, $asset_code)) {
                                $balances[$balance->asset_code] = $balance->balance;
                            }
                        }

                        return $balances;
                    }

                } else {
                    foreach ($result->balances as $balance) {
                        if (!empty($balance->asset_code)) {
                            $balances[$balance->asset_code] = $balance->balance;
                        }
                    }

                    return $balances;
                }
            }

        } catch (\Phalcon\Exception $e) {
            return false;
        }
        return false;

    }

    public static function isAccountExist($accountId, $host, $port)
    {

        try {
            $host = trim($host, '/');

            $getLink = 'http://' . $host . ':' . $port . '/accounts/' . $accountId;
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_URL, $getLink);
            $result = json_decode(curl_exec($ch));

            if (isset($result) && !empty($result->account_id) && $result->account_id == $accountId) {
                return true;
            }

        } catch (\Phalcon\Exception $e) {
            return false;
        }
        return false;
    }

    public static function isValidAccountId($accountId)
    {

        try {
            $decoded = self::decodeCheck("accountId", $accountId);
            if (count($decoded) !== 32) {
                return false;
            }
        } catch (\Phalcon\Exception $e) {
            return false;
        }
        return true;
    }

    public static function getAccountType($accountId, $host, $port)
    {

        try {
            $host = trim($host, '/');

            $getLink = 'http://' . $host . ':' . $port . '/accounts/' . $accountId;
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_URL, $getLink);
            $result = json_decode(curl_exec($ch));

            if (isset($result) && !empty($result->account_id) && $result->account_id == $accountId && isset($result->type_i)) {
                return $result->type_i;
            }

        } catch (\Phalcon\Exception $e) {
            return -1;
        }
        return -1;
    }

    public static function getAccountLastTXs($accountId, $count, $host, $port)
    {
        try {
            $host = trim($host, '/');

            $params = http_build_query([
                'order' => 'desc',
                'limit' => $count
            ]);

            $getLink = 'http://' . $host . ':' . $port . '/accounts/' . $accountId . '/transactions?' . $params;
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_URL, $getLink);
            $result = json_decode(curl_exec($ch));

            if (isset($result) && !empty($result->_embedded) && !empty($result->_embedded->records)) {
                return $result->_embedded->records;
            }

        } catch (\Phalcon\Exception $e) {
            return [];
        }
        return [];
    }

    public static function encodeCheck($versionByteName, $data)
    {
        if (empty($data)) {
            return false;
        }

        $data = unpack('C*', base64_decode($data));
        $versionByte = self::$versionBytes[$versionByteName];

        if (empty($versionByte)) {
            return false;
        }

        array_unshift($data, $versionByte);
        $checksum = self::calculateChecksum($data);

        $data = array_merge($data, $checksum);
        $data = array_map(function ($a) {
            return chr($a);
        }, $data);

        $data = implode($data);
        $base32 = new Strkey\Base32();
        return $base32->encode($data);

    }

    private static function decodeCheck($versionByteName, $encoded)
    {
        if (!is_string($encoded)) {
            throw new \Exception("encoded argument must be of type String");
        }

        $base32 = new Strkey\Base32();

        $decoded = $base32->decode($encoded, true);

        if (empty($decoded) || !is_array($decoded)) {
            return false;
        }

        $versionByte = $decoded[0];
        $payload = array_slice($decoded, 0, -2);

        $data = array_slice($payload, 1);
        $checksum = array_slice($decoded, -2);


        if ($base32->encode($base32->decode($encoded)) != $encoded) {
            //throw new \Exception('invalid encoded string');
            return false;
        }

        $expectedVersion = self::$versionBytes[$versionByteName];
        if (empty($expectedVersion)) {
            //throw new \Exception($versionByteName . ' is not a valid version byte name.  expected one of "accountId" or "seed"');
            return false;
        }
        if ($versionByte != $expectedVersion) {
            //throw new \Exception('invalid version byte. expected ' . $expectedVersion . ', got ' . $versionByte);
            return false;
        }

        $expectedChecksum = self::calculateChecksum($payload);

        if (!self::verifyChecksum($expectedChecksum, $checksum)) {
            //throw new \Exception('invalid checksum');
            return false;
        }

        return $data;
    }

    private static function verifyChecksum($expected, $actual)
    {
        if (count($expected) !== count($actual)) {
            return false;
        }

        if (count($expected) === 0) {
            return true;
        }

        for ($i = 0; $i < count($expected); $i++) {
            if ($expected[$i] != $actual[$i]) {
                return false;
            }
        }

        return true;
    }

    private static function uInt16($value, $offset)
    {
        $value = +$value;
        $offset = $offset >> 0;

        $buffer = [];
        $buffer[$offset] = $value & 0xff;
        $buffer[$offset + 1] = $value >> 8;

        return $buffer;
    }

    private static function calculateChecksum($payload)
    {
        // This code calculates CRC16-XModem checksum of payload
        // and returns it as Buffer in little-endian order.
        $crc16 = new Strkey\CRC16XModem();
        $crc16->update($payload);

        return self::uInt16($crc16->getChecksum(), 0);
    }
}