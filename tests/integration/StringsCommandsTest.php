<?php

namespace Test\Integration;

use PHPUnit_Framework_TestCase;
use RedisClient\Exception\ErrorResponseException;
use RedisClient\RedisClient;

class StringsCommandsTest extends PHPUnit_Framework_TestCase {

    /**
     * @var RedisClient
     */
    protected static $Redis;

    /**
     * @var array
     */
    protected static $fields;

    const REDIS_RESPONSE_ERROR_WRONGTYPE = 'WRONGTYPE Operation against a key holding the wrong kind of value';
    const REDIS_RESPONSE_ERROR_NOT_INTEGER = 'ERR value is not an integer or out of range';
    const REDIS_RESPONSE_ERROR_NOT_FLOAT = 'ERR value is not a valid float';
    const REDIS_RESPONSE_ERROR_EXPIRE_TIME = 'ERR invalid expire time in set';
    const REDIS_RESPONSE_ERROR_EXPIRE_TIME_SETEX = 'ERR invalid expire time in setex';

    /**
     * @inheritdoc
     */
    public static function setUpBeforeClass() {
        self::$Redis = new RedisClient([
            'server' => 'tcp://127.0.0.1:6379'
        ]);
    }

    /**
     * @inheritdoc
     */
    public static function tearDownAfterClass() {
        self::$Redis->flushdb();
    }

    /**
     * @inheritdoc
     */
    protected function setUp() {
        self::$Redis->flushdb();
        self::$fields = [
            'string'  => 'value',
            'integer' => 42,
            'true'    => true,
            'false'   => false,
            'float'   => 3.14159265,
            'e'       => '5.0e3',
            'null'    => null,
            ''        => 'empty',
            'empty'   => '',
            'bin'     => call_user_func_array('pack', ['N*'] + range(0, 255))
        ];
        self::$Redis->hmset('hash', self::$fields);
        foreach (self::$fields as $key => $value) {
            self::$Redis->set($key, $value);
        }
    }

    public function test_append() {
        $Redis = self::$Redis;

        $this->assertSame(strlen('append-string'), $Redis->append('new-key', 'append-string'));
        $this->assertSame('append-string', $Redis->get('new-key'));

        foreach (self::$fields as $key => $value) {
            $newValue = (string) self::$fields[$key] . ' append-string';
            $this->assertSame(strlen($newValue), $Redis->append($key, ' append-string'));
            $this->assertSame($newValue, $Redis->get($key));
        }

        try {
            $Redis->append('hash', 'field');
            $this->assertTrue(false);
        } catch (ErrorResponseException $Ex) {
            $this->assertSame(self::REDIS_RESPONSE_ERROR_WRONGTYPE, $Ex->getMessage());
        }
    }

    public function test_bitcount() {
        $Redis = self::$Redis;

        $this->assertSame(0, $Redis->bitcount('new-key'));

        foreach (self::$fields as $key => $value) {
            $value = (string) self::$fields[$key];
            $this->assertTrue(is_int($Redis->bitcount($key)));
            if ($value) {
                $this->assertTrue(0 < $Redis->bitcount($key));
            } else {
                $this->assertSame(0, $Redis->bitcount($key));
            }
        }

        try {
            $Redis->bitcount('hash');
            $this->assertTrue(false);
        } catch (ErrorResponseException $Ex) {
            $this->assertSame(self::REDIS_RESPONSE_ERROR_WRONGTYPE, $Ex->getMessage());
        }
    }

    public function test_bitop() {
        $Redis = self::$Redis;

        $this->assertSame(0, $Redis->bitop('AND', 'new-key', ['key1', 'key2']));
        $this->assertSame(0, $Redis->bitop('AND', 'new-key', 'key3'));

        for ($i = 0; $i < 10; $i++) {
            $Redis->set('bit1', chr($bit1 = mt_rand(0, 255)));
            $Redis->set('bit2', chr($bit2 = mt_rand(0, 255)));

            $this->assertTrue(is_int($Redis->bitop('AND', 'bit', ['bit1', 'bit2'])));
            $this->assertEquals(chr($bit1 & $bit2), $Redis->get('bit'));

            $this->assertTrue(is_int($Redis->bitop('OR', 'bit', ['bit1', 'bit2'])));
            $this->assertEquals(chr($bit1 | $bit2), $Redis->get('bit'));

            $this->assertTrue(is_int($Redis->bitop('XOR', 'bit', ['bit1', 'bit2'])));
            $this->assertEquals(chr($bit1 ^ $bit2), $Redis->get('bit'));

            $this->assertTrue(is_int($Redis->bitop('NOT', 'bit', 'bit1')));
            $this->assertEquals(chr(~$bit1), $Redis->get('bit'));
        }
    }

    public function test_bitpos() {
        $Redis = self::$Redis;

        $this->assertSame(true, $Redis->set('mykey', "\xff\xf0\x00"));
        $this->assertSame(12, $Redis->bitpos('mykey', 0));

        $this->assertSame(true, $Redis->set('mykey', "\x00\xff\xf0"));
        $this->assertSame(8, $Redis->bitpos('mykey', 1, 0));
        $this->assertSame(16, $Redis->bitpos('mykey', 1, 2));

        $this->assertSame(true, $Redis->set('mykey', "\x00\x00\x00"));
        $this->assertSame(-1, $Redis->bitpos('mykey', 1));
        $this->assertSame(0, $Redis->bitpos('mykey', 0));

        $this->assertSame(true, $Redis->set('mykey', "\x00\x00\x00"));
        $this->assertSame(-1, $Redis->bitpos('mykey', 1));
        $this->assertSame(0, $Redis->bitpos('mykey', 0));

        $this->assertSame(true, $Redis->set('mykey', chr(0b00001111)));
        $this->assertSame(4, $Redis->bitpos('mykey', 1));
        $this->assertSame(0, $Redis->bitpos('mykey', 0));

        $this->assertSame(true, $Redis->set('mykey', chr(0b00000011)));
        $this->assertSame(6, $Redis->bitpos('mykey', 1));
        $this->assertSame(0, $Redis->bitpos('mykey', 0));

        $this->assertSame(true, $Redis->set('mykey', chr(0b00000000).chr(0b11111111)));
        $this->assertSame(8, $Redis->bitpos('mykey', 1));
        $this->assertSame(0, $Redis->bitpos('mykey', 0));

        $this->assertSame(true, $Redis->set('mykey', chr(0b00000000).chr(0b00000001)));
        $this->assertSame(15, $Redis->bitpos('mykey', 1));
        $this->assertSame(0, $Redis->bitpos('mykey', 0));

        $this->assertSame(true, $Redis->set('mykey', chr(0b10000000).chr(0b00000001)));
        $this->assertSame(0, $Redis->bitpos('mykey', 1));
        $this->assertSame(1, $Redis->bitpos('mykey', 0));

        try {
            $Redis->bitpos('hash', 0);
            $this->assertTrue(false);
        } catch (ErrorResponseException $Ex) {
            $this->assertSame(self::REDIS_RESPONSE_ERROR_WRONGTYPE, $Ex->getMessage());
        }
    }

    public function test_decr() {
        $Redis = self::$Redis;

        $this->assertSame(-1, $Redis->decr('key'));
        $this->assertSame(-2, $Redis->decr('key'));
        $this->assertSame(-3, $Redis->decr('key'));
        $this->assertSame(41, $Redis->decr('integer'));
        $this->assertSame(40, $Redis->decr('integer'));

        try {
            $this->assertSame(3.14159265, $Redis->decr('float'));
            $this->assertTrue(false);
        } catch (ErrorResponseException $Ex) {
            $this->assertSame(self::REDIS_RESPONSE_ERROR_NOT_INTEGER, $Ex->getMessage());
        }

        // I don't know why it happens, but it is real Redis behavior
        $this->assertSame(-1, $Redis->decr('bin'));

        try {
            $this->assertSame(-1, $Redis->decr('string'));
            $this->assertTrue(false);
        } catch (ErrorResponseException $Ex) {
            $this->assertSame(self::REDIS_RESPONSE_ERROR_NOT_INTEGER, $Ex->getMessage());
        }

        try {
            $Redis->decr('hash');
            $this->assertTrue(false);
        } catch (ErrorResponseException $Ex) {
            $this->assertSame(self::REDIS_RESPONSE_ERROR_WRONGTYPE, $Ex->getMessage());
        }
    }

    public function test_decrby() {
        $Redis = self::$Redis;

        $this->assertSame(-7, $Redis->decrby('key', 7));
        $this->assertSame(-11, $Redis->decrby('key', 4));
        $this->assertSame(11, $Redis->decrby('key', -22));
        $this->assertSame(40, $Redis->decrby('integer', 2));
        $this->assertSame(40, $Redis->decrby('integer', 0));

        try {
            $this->assertSame(3.14159265, $Redis->decrby('float', 1));
            $this->assertTrue(false);
        } catch (ErrorResponseException $Ex) {
            $this->assertSame(self::REDIS_RESPONSE_ERROR_NOT_INTEGER, $Ex->getMessage());
        }

        // I don't know why it happens, but it is real Redis behavior
        $this->assertSame(-17, $Redis->decrby('bin', 17));

        try {
            $this->assertSame(-8, $Redis->decrby('string', 8));
            $this->assertTrue(false);
        } catch (ErrorResponseException $Ex) {
            $this->assertSame(self::REDIS_RESPONSE_ERROR_NOT_INTEGER, $Ex->getMessage());
        }

        try {
            $Redis->decrby('hash', 2);
            $this->assertTrue(false);
        } catch (ErrorResponseException $Ex) {
            $this->assertSame(self::REDIS_RESPONSE_ERROR_WRONGTYPE, $Ex->getMessage());
        }
    }

    public function test_get() {
        $Redis = self::$Redis;

        $this->assertSame(null, $Redis->get('new-key'));

        foreach (self::$fields as $key => $value) {
            $value = (string) self::$fields[$key];
            $this->assertSame($value, $Redis->get($key));
        }

        try {
            $Redis->get('hash');
            $this->assertTrue(false);
        } catch (ErrorResponseException $Ex) {
            $this->assertSame(self::REDIS_RESPONSE_ERROR_WRONGTYPE, $Ex->getMessage());
        }
    }

    public function test_getrange() {
        $Redis = self::$Redis;

        $this->assertSame('', $Redis->getrange('new-key', 0, 10));

        foreach (self::$fields as $key => $value) {
            $value = (string) self::$fields[$key];
            $this->assertSame(substr($value, 1, 5) ?: '', $Redis->getrange($key, 1, 5));
            $this->assertSame(substr($value, -5) ?: '', $Redis->getrange($key, -5, -1));
            $this->assertSame(substr($value, 0) ?: '', $Redis->getrange($key, 0, -1));
        }

        try {
            $Redis->get('hash');
            $this->assertTrue(false);
        } catch (ErrorResponseException $Ex) {
            $this->assertSame(self::REDIS_RESPONSE_ERROR_WRONGTYPE, $Ex->getMessage());
        }
    }

    public function test_getset() {
        $Redis = self::$Redis;

        $this->assertSame(null, $Redis->getset('new-key', 'new-value-1'));
        $this->assertSame('new-value-1', $Redis->getset('new-key', 'new-value-2'));
        $this->assertSame('new-value-2', $Redis->getset('new-key', 'new-value-3'));
        $this->assertSame('new-value-3', $Redis->getset('new-key', 'new-value-3'));
        $this->assertSame('new-value-3', $Redis->getset('new-key', 'new-value-2'));
        $this->assertSame('new-value-2', $Redis->getset('new-key', 'new-value-1'));

        try {
            $Redis->getset('hash', 'value');
            $this->assertTrue(false);
        } catch (ErrorResponseException $Ex) {
            $this->assertSame(self::REDIS_RESPONSE_ERROR_WRONGTYPE, $Ex->getMessage());
        }
    }

    public function test_incr() {
        $Redis = self::$Redis;

        $this->assertSame(1, $Redis->incr('key'));
        $this->assertSame(2, $Redis->incr('key'));
        $this->assertSame(3, $Redis->incr('key'));
        $this->assertSame(43, $Redis->incr('integer'));
        $this->assertSame(44, $Redis->incr('integer'));

        try {
            $this->assertSame(3.14159265, $Redis->incr('float'));
            $this->assertTrue(false);
        } catch (ErrorResponseException $Ex) {
            $this->assertSame(self::REDIS_RESPONSE_ERROR_NOT_INTEGER, $Ex->getMessage());
        }

        // I don't know why it happens, but it is real Redis behavior
        $this->assertSame(1, $Redis->incr('bin'));

        try {
            $this->assertSame(1, $Redis->incr('string'));
            $this->assertTrue(false);
        } catch (ErrorResponseException $Ex) {
            $this->assertSame(self::REDIS_RESPONSE_ERROR_NOT_INTEGER, $Ex->getMessage());
        }

        try {
            $Redis->incr('hash');
            $this->assertTrue(false);
        } catch (ErrorResponseException $Ex) {
            $this->assertSame(self::REDIS_RESPONSE_ERROR_WRONGTYPE, $Ex->getMessage());
        }
    }

    public function test_incrby() {
        $Redis = self::$Redis;

        $this->assertSame(7, $Redis->incrby('key', 7));
        $this->assertSame(11, $Redis->incrby('key', 4));
        $this->assertSame(-11, $Redis->incrby('key', -22));
        $this->assertSame(44, $Redis->incrby('integer', 2));
        $this->assertSame(44, $Redis->incrby('integer', 0));

        try {
            $this->assertSame(3.14159265, $Redis->incrby('float', 1));
            $this->assertTrue(false);
        } catch (ErrorResponseException $Ex) {
            $this->assertSame(self::REDIS_RESPONSE_ERROR_NOT_INTEGER, $Ex->getMessage());
        }

        // I don't know why it happens, but it is real Redis behavior
        $this->assertSame(17, $Redis->incrby('bin', 17));

        try {
            $this->assertSame(8, $Redis->incrby('string', 8));
            $this->assertTrue(false);
        } catch (ErrorResponseException $Ex) {
            $this->assertSame(self::REDIS_RESPONSE_ERROR_NOT_INTEGER, $Ex->getMessage());
        }

        try {
            $Redis->incrby('hash', 2);
            $this->assertTrue(false);
        } catch (ErrorResponseException $Ex) {
            $this->assertSame(self::REDIS_RESPONSE_ERROR_WRONGTYPE, $Ex->getMessage());
        }
    }

    public function test_incrbyfloat() {
        $Redis = self::$Redis;

        $this->assertSame('7.7', $Redis->incrbyfloat('key', 7.7));
        $this->assertSame('11.7', $Redis->incrbyfloat('key', 4));
        $this->assertSame('-1', $Redis->incrbyfloat('key', -12.7));
        $this->assertSame('44.2', $Redis->incrbyfloat('integer', 2.2));
        $this->assertSame('44.2', $Redis->incrbyfloat('integer', 0));

        $this->assertSame('4.15159265', $Redis->incrbyfloat('float', 1.01));
        $this->assertSame('3.14159265', $Redis->incrbyfloat('float', -1.01));

        // I don't know why it happens, but it is real Redis behavior
        $this->assertSame('17.5', $Redis->incrbyfloat('bin', 17.5));

        try {
            $this->assertSame(8, $Redis->incrbyfloat('string', 8));
            $this->assertTrue(false);
        } catch (ErrorResponseException $Ex) {
            $this->assertSame(self::REDIS_RESPONSE_ERROR_NOT_FLOAT, $Ex->getMessage());
        }

        try {
            $Redis->decr('hash');
            $this->assertTrue(false);
        } catch (ErrorResponseException $Ex) {
            $this->assertSame(self::REDIS_RESPONSE_ERROR_WRONGTYPE, $Ex->getMessage());
        }
    }

    public function test_mget() {
        $Redis = self::$Redis;

        $this->assertSame(['key' => null], $Redis->mget('key'));
        $this->assertSame(['key' => null], $Redis->mget(['key', 'key']));

        $this->assertSame([
                'key' => null,
                'string' => 'value',
                'integer' => '42',
                '' => 'empty',
                'hash' => null
            ],
            $Redis->mget(['key', 'string' , 'integer', '', 'integer', 'hash'])
        );

        $this->assertSame(['hash' => null], $Redis->mget('hash'));
    }

    public function test_msetnx() {
        $Redis = self::$Redis;

        $this->assertSame(1, $Redis->msetnx(['key'=>'value']));
        $this->assertSame(1, $Redis->msetnx(['key1'=>'value1', 'key2'=>'value2']));
        $this->assertSame([
            'key' => 'value',
            'key1' => 'value1',
            'key2' => 'value2',
        ],
            $Redis->mget(['key', 'key1', 'key2'])
        );

        $this->assertSame(0, $Redis->msetnx(['hash'=>'value1', 'test'=>'value2']));
        $this->assertSame([
            'hash' => null,
            'test' => null,
        ],
            $Redis->mget(['hash', 'test'])
        );
    }

    public function test_psetex() {
        $Redis = self::$Redis;

        $this->assertSame(true, $Redis->psetex('key', 1000, 'value'));
        $this->assertSame('value', $Redis->get('key'));
        $this->assertTrue(is_int($Redis->ttl('key')));

        $this->assertSame(true, $Redis->psetex('key', 1, 'value'));
        usleep(2000);
        $this->assertSame(null, $Redis->get('key'));
    }

    public function test_set() {
        $Redis = self::$Redis;

        $this->assertSame(true, $Redis->set('key','value'));
        $this->assertSame(true, $Redis->set('key','value2', null, null, 'XX'));
        $this->assertSame('value2', $Redis->get('key'));
        $this->assertSame(-1, $Redis->ttl('key'));

        $this->assertSame(true, $Redis->set('key1','value1', 100, null, 'NX'));
        $this->assertSame('value1', $Redis->get('key1'));
        $this->assertGreaterThanOrEqual(99, $Redis->ttl('key1'));
        $this->assertLessThanOrEqual(100, $Redis->ttl('key1'));

        $this->assertSame(true, $Redis->set('key2','value2', null, 100000, 'NX'));
        $this->assertSame('value1', $Redis->get('key1'));
        $this->assertGreaterThanOrEqual(99, $Redis->ttl('key1'));
        $this->assertLessThanOrEqual(100, $Redis->ttl('key1'));

        $this->assertSame(true, $Redis->set('key2','value2'));
        $this->assertSame(-1, $Redis->ttl('key2'));

        try {
            $this->assertSame(true, $Redis->set('key1','value1', -100));
            $this->assertTrue(false);
        } catch (ErrorResponseException $Ex) {
            $this->assertSame(self::REDIS_RESPONSE_ERROR_EXPIRE_TIME, $Ex->getMessage());
        }

        try {
            $this->assertSame(true, $Redis->set('key1','value1', 0, -100));
            $this->assertTrue(false);
        } catch (ErrorResponseException $Ex) {
            $this->assertSame(self::REDIS_RESPONSE_ERROR_EXPIRE_TIME, $Ex->getMessage());
        }
    }

    public function test_setbit() {
        $Redis = self::$Redis;

        $this->assertSame(true, $Redis->set('mykey', chr(0b00000000)));

        $this->assertSame(0, $Redis->setbit('mykey', 0, 1));
        $this->assertSame(chr(0b10000000), $Redis->get('mykey'));

        $this->assertSame(0, $Redis->setbit('mykey', 3, 1));
        $this->assertSame(chr(0b10010000), $Redis->get('mykey'));

        $this->assertSame(0, $Redis->setbit('mykey', 4, 1));
        $this->assertSame(chr(0b10011000), $Redis->get('mykey'));

        $this->assertSame(1, $Redis->setbit('mykey', 0, 0));
        $this->assertSame(chr(0b00011000), $Redis->get('mykey'));

        $this->assertSame(0, $Redis->setbit('mykey', 0, 0));
        $this->assertSame(chr(0b00011000), $Redis->get('mykey'));

        $this->assertSame(0, $Redis->setbit('mykey', 15, 0));
        $this->assertSame(chr(0b00011000).chr(0b00000000), $Redis->get('mykey'));

        $this->assertSame(0, $Redis->setbit('mykey', 23, 1));
        $this->assertSame(chr(0b00011000).chr(0b00000000).chr(0b00000001), $Redis->get('mykey'));

        $this->assertSame(0, $Redis->setbit('mykey1', 2, 1));
        $this->assertSame(chr(0b00100000), $Redis->get('mykey1'));

        $this->assertSame(0, $Redis->setbit('mykey2', 23, 1));
        $this->assertSame(chr(0b00000000).chr(0b00000000).chr(0b00000001), $Redis->get('mykey2'));

        try {
            $Redis->bitpos('hash', 0);
            $this->assertTrue(false);
        } catch (ErrorResponseException $Ex) {
            $this->assertSame(self::REDIS_RESPONSE_ERROR_WRONGTYPE, $Ex->getMessage());
        }
    }

    public function test_setex() {
        $Redis = self::$Redis;

        $this->assertSame(true, $Redis->setex('key', 10, 'value'));
        $this->assertSame(true, $Redis->setex('key', 20 ,'value2'));
        $this->assertSame('value2', $Redis->get('key'));

        $this->assertSame(true, $Redis->setex('key1', 100, 'value1'));
        $this->assertSame('value1', $Redis->get('key1'));
        $this->assertGreaterThanOrEqual(99, $Redis->ttl('key1'));
        $this->assertLessThanOrEqual(100, $Redis->ttl('key1'));

        try {
            $this->assertSame(true, $Redis->setex('key1', 0,'value1'));
            $this->assertTrue(false);
        } catch (ErrorResponseException $Ex) {
            $this->assertSame(self::REDIS_RESPONSE_ERROR_EXPIRE_TIME_SETEX, $Ex->getMessage());
        }

        try {
            $this->assertSame(true, $Redis->setex('key1', -100, 'value1'));
            $this->assertTrue(false);
        } catch (ErrorResponseException $Ex) {
            $this->assertSame(self::REDIS_RESPONSE_ERROR_EXPIRE_TIME_SETEX, $Ex->getMessage());
        }
    }

    public function test_setnx() {
        $Redis = self::$Redis;

        $this->assertSame(1, $Redis->setnx('key', 'value'));
        $this->assertSame(0, $Redis->setnx('key', 'value2'));
        $this->assertSame('value', $Redis->get('key'));
        $this->assertSame(-1, $Redis->ttl('key'));

        $this->assertSame(1, $Redis->setnx('key1', 'value1'));
        $this->assertSame('value1', $Redis->get('key1'));

        $this->assertSame(0, $Redis->setnx('hash', 'value1'));
    }

    public function test_setrange() {
        $Redis = self::$Redis;

        $this->assertSame(true, $Redis->set('key1', 'Hello World'));
        $this->assertSame(11, $Redis->setrange('key1', 6, 'Redis'));
        $this->assertSame('Hello Redis', $Redis->get('key1'));

        $this->assertSame(11, $Redis->setrange('key2', 6, 'Redis'));
        $this->assertSame("\x00\x00\x00\x00\x00\x00Redis", $Redis->get('key2'));

        try {
            $this->assertSame(11, $Redis->setrange('hash', 6, 'Redis'));
            $this->assertTrue(false);
        } catch (ErrorResponseException $Ex) {
            $this->assertSame(self::REDIS_RESPONSE_ERROR_WRONGTYPE, $Ex->getMessage());
        }
    }

    public function test_strlen() {
        $Redis = self::$Redis;

        $this->assertSame(0, $Redis->strlen('key'));

        foreach (self::$fields as $key => $value) {
            $value = (string) self::$fields[$key];
            $this->assertSame(strlen($value), $Redis->strlen($key));
        }

        try {
            $Redis->strlen('hash');
            $this->assertTrue(false);
        } catch (ErrorResponseException $Ex) {
            $this->assertSame(self::REDIS_RESPONSE_ERROR_WRONGTYPE, $Ex->getMessage());
        }
    }

}