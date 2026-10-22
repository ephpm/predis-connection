<?php

declare(strict_types=1);

namespace Ephpm\Predis;

/**
 * Optional backend capabilities that map onto the `ephpm_kv_*` functions
 * added in ePHPm v0.13.0 (hashes, multi-key strings, GETDEL/GETEX, PERSIST,
 * APPEND/STRLEN/TYPE, SCAN and compare-and-set).
 *
 * Kept separate from {@see KvOpsInterface} so existing custom backends keep
 * compiling. {@see KvConnection} only calls a method here after
 * {@see supports()} has confirmed the matching operation is available; when
 * it is not (an older ePHPm, or a backend that does not implement this
 * interface) the connection keeps its pre-v0.13 behavior for that command.
 *
 * Method semantics mirror the SAPI functions one-to-one (see each method);
 * TTLs are seconds unless the name says otherwise.
 */
interface ExtendedKvOpsInterface extends KvOpsInterface
{
    /**
     * Every operation name {@see supports()} may be asked about. Each is the
     * suffix of the SAPI function that backs it (`hset` = `ephpm_kv_hset`).
     */
    public const OPS = [
        'hget', 'hset', 'hsetnx', 'hmget', 'hgetall', 'hdel', 'hexists',
        'hlen', 'hkeys', 'hvals', 'hincr_by', 'hexpire', 'httl', 'hpttl',
        'hpersist', 'mget', 'mset', 'getdel', 'getex', 'persist',
        'set_if_eq', 'append', 'strlen', 'type', 'scan',
    ];

    /**
     * Whether the operation named `$op` (one of {@see OPS}) is available.
     */
    public function supports(string $op): bool;

    /** `ephpm_kv_hget`: the field's value, or null if absent (or on failure). */
    public function hget(string $key, string $field): ?string;

    /**
     * `ephpm_kv_hset` (array form): write every field.
     *
     * @param array<array-key, string> $fields field => value
     *
     * @return int|false number of fields created, false on failure
     */
    public function hset(string $key, array $fields): int|false;

    /** `ephpm_kv_hsetnx`: true if set, false if the field existed (or failure). */
    public function hsetnx(string $key, string $field, string $value): bool;

    /**
     * `ephpm_kv_hmget`.
     *
     * @param list<string> $fields
     *
     * @return list<string|null>|false aligned with `$fields`; false on failure
     */
    public function hmget(string $key, array $fields): array|false;

    /**
     * `ephpm_kv_hgetall`.
     *
     * @return array<array-key, string>|false field => value; false on failure
     */
    public function hgetall(string $key): array|false;

    /**
     * `ephpm_kv_hdel`.
     *
     * @param list<string> $fields
     */
    public function hdel(string $key, array $fields): int;

    /** `ephpm_kv_hexists`. */
    public function hexists(string $key, string $field): bool;

    /** `ephpm_kv_hlen`: 0 for a missing hash. */
    public function hlen(string $key): int;

    /**
     * `ephpm_kv_hkeys`.
     *
     * @return list<string|int>|false
     */
    public function hkeys(string $key): array|false;

    /**
     * `ephpm_kv_hvals`.
     *
     * @return list<string>|false
     */
    public function hvals(string $key): array|false;

    /** `ephpm_kv_hincr_by`: new value; false for a non-integer field, overflow or failure. */
    public function hincrBy(string $key, string $field, int $delta): int|false;

    /**
     * `ephpm_kv_hexpire` for one field: 1 if the field got the TTL, 0 if it
     * does not exist, false if `$ttlSeconds` is out of range or on failure.
     */
    public function hexpire(string $key, string $field, int $ttlSeconds): int|false;

    /** `ephpm_kv_httl`: seconds remaining, -1 no TTL, -2 no such field. */
    public function httl(string $key, string $field): int;

    /** `ephpm_kv_hpttl`: milliseconds remaining, -1 no TTL, -2 no such field. */
    public function hpttl(string $key, string $field): int;

    /** `ephpm_kv_hpersist`: true if a field TTL was removed. */
    public function hpersist(string $key, string $field): bool;

    /**
     * `ephpm_kv_mget`.
     *
     * @param list<string> $keys
     *
     * @return list<string|null>|false aligned with `$keys`; false on failure
     */
    public function mget(array $keys): array|false;

    /**
     * `ephpm_kv_mset`: write every pair with the same TTL (0 = none).
     *
     * @param array<array-key, string> $values key => value
     */
    public function mset(array $values, int $ttlSeconds = 0): bool;

    /** `ephpm_kv_getdel`: the value (then deleted), or null if missing. */
    public function getdel(string $key): ?string;

    /** `ephpm_kv_getex`: read and set the TTL (> 0 seconds) or remove it (0). */
    public function getex(string $key, int $ttlSeconds): ?string;

    /** `ephpm_kv_persist`: true if a TTL was removed. */
    public function persist(string $key): bool;

    /**
     * `ephpm_kv_set_if_eq`: replace the value only while it equals
     * `$expected`; never creates the key. `$ttlSeconds` > 0 = new TTL,
     * 0 = no TTL, -1 = keep the current TTL.
     */
    public function setIfEq(string $key, string $expected, string $value, int $ttlSeconds = 0): bool;

    /** `ephpm_kv_append`: the new length, false on failure. */
    public function append(string $key, string $value): int|false;

    /** `ephpm_kv_strlen`: 0 if missing. */
    public function strlen(string $key): int;

    /** `ephpm_kv_type`: "string", "hash" or "none". */
    public function type(string $key): string;

    /**
     * `ephpm_kv_scan`: one step of an incremental keyspace scan.
     *
     * @return array{0: int, 1: list<string>}|false [next cursor (0 = done), keys]
     */
    public function scan(int $cursor, ?string $match = null, int $count = 10): array|false;
}
