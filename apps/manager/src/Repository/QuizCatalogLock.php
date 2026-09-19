<?php

namespace App\Repository;

use PDO;

final class QuizCatalogLock
{
    private const KEY = 4891190026017;

    public static function transaction(PDO $db): void
    {
        $db->query('SELECT pg_advisory_xact_lock('.self::KEY.')')->fetchColumn();
    }

    public static function session(PDO $db): void
    {
        $db->query('SELECT pg_advisory_lock('.self::KEY.')')->fetchColumn();
    }

    public static function release(PDO $db): void
    {
        $db->query('SELECT pg_advisory_unlock('.self::KEY.')')->fetchColumn();
    }
}
