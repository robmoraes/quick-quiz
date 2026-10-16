<?php

namespace App\Service;

use App\Repository\ManagerDatabase;
use PDO;
use RuntimeException;

/** Operator-only provisioning; never called during application startup. */
final class QuizRuntimeReaderProvisioner
{
    public const VIEWS = ['state', 'themes', 'topics', 'topic_tags', 'topic_translations', 'question_keys', 'question_translations', 'answers', 'solution_prompts'];

    public function __construct(private readonly ManagerDatabase $database) {}

    /** @return array<string,mixed> */
    public function run(string $role, string $password, bool $apply): array
    {
        if (!preg_match('/^[a-z_][a-z0-9_]{0,62}$/D', $role)) {
            throw new RuntimeException('Reader role must be a lowercase PostgreSQL identifier of at most 63 characters.');
        }
        $ownsTransaction = !$this->database->connection()->inTransaction();
        return $this->database->transactional(function (PDO $db) use ($role, $password, $apply, $ownsTransaction): array {
            if (!$apply && $ownsTransaction) {
                $db->exec('SET TRANSACTION READ ONLY');
            }
            $check = $db->prepare('SELECT r.oid,r.rolsuper,r.rolcreatedb,r.rolcreaterole,r.rolreplication,r.rolbypassrls,
                EXISTS(SELECT 1 FROM pg_auth_members m WHERE m.member=r.oid) AS has_memberships,
                EXISTS(SELECT 1 FROM pg_class c WHERE c.relowner=r.oid) OR EXISTS(SELECT 1 FROM pg_namespace n WHERE n.nspowner=r.oid)
                    OR EXISTS(SELECT 1 FROM pg_database d WHERE d.datdba=r.oid) AS owns_objects
                FROM pg_roles r WHERE r.rolname=:role');
            $check->execute(['role' => $role]);
            $existing = $check->fetch(PDO::FETCH_ASSOC);
            if (is_array($existing)) {
                foreach (['rolsuper', 'rolcreatedb', 'rolcreaterole', 'rolreplication', 'rolbypassrls', 'has_memberships', 'owns_objects'] as $flag) {
                    if (in_array($existing[$flag], [true, 1, '1', 't', 'true'], true)) {
                        throw new RuntimeException('Existing reader role has ownership, membership, or elevated privileges; choose a dedicated role.');
                    }
                }
            }
            $database = (string) $db->query('SELECT current_database()')->fetchColumn();
            $report = ['role' => $role, 'database' => $database, 'schema' => 'quiz_runtime_v1', 'views' => self::VIEWS,
                'revokePublicTemporary' => true, 'applied' => false];
            if (!$apply) {
                return $report;
            }
            if ($password === '' || str_contains($password, "\0")) {
                throw new RuntimeException('QUIZ_DATABASE_READER_PASSWORD (or __FILE) is required for provisioning.');
            }
            $identifier = '"'.$role.'"';
            $dbIdentifier = '"'.str_replace('"', '""', $database).'"';
            $db->exec(($existing ? 'ALTER' : 'CREATE').' ROLE '.$identifier
                .' LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS PASSWORD '.$db->quote($password));
            $db->exec('REVOKE ALL PRIVILEGES ON DATABASE '.$dbIdentifier.' FROM '.$identifier);
            // This is an explicit operation on a dedicated application database, not an implicit startup change.
            $db->exec('REVOKE TEMPORARY ON DATABASE '.$dbIdentifier.' FROM PUBLIC');
            $db->exec('GRANT CONNECT ON DATABASE '.$dbIdentifier.' TO '.$identifier);
            $db->exec('REVOKE ALL ON SCHEMA public,quiz_runtime_v1 FROM '.$identifier);
            $db->exec('REVOKE ALL ON ALL TABLES IN SCHEMA public,quiz_runtime_v1 FROM '.$identifier);
            $db->exec('REVOKE ALL ON ALL SEQUENCES IN SCHEMA public,quiz_runtime_v1 FROM '.$identifier);
            $db->exec('GRANT USAGE ON SCHEMA quiz_runtime_v1 TO '.$identifier);
            foreach (self::VIEWS as $view) {
                $db->exec('GRANT SELECT ON quiz_runtime_v1.'.$view.' TO '.$identifier);
            }
            $db->exec('ALTER ROLE '.$identifier.' SET search_path=quiz_runtime_v1,pg_catalog');
            $db->exec('ALTER ROLE '.$identifier.' SET default_transaction_read_only=on');
            $db->exec("ALTER ROLE ".$identifier." SET statement_timeout='5s'");
            $db->exec("ALTER ROLE ".$identifier." SET idle_in_transaction_session_timeout='15s'");
            $privileges = $db->prepare("SELECT EXISTS(SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
                    WHERE n.nspname='public' AND c.relkind IN ('r','p','v','m','f')
                    AND has_table_privilege(:role,c.oid,'SELECT,INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER'))
                OR has_schema_privilege(:role,'public','CREATE') OR has_schema_privilege(:role,'quiz_runtime_v1','CREATE')
                OR has_database_privilege(:role,current_database(),'CREATE,TEMP')
                AS unsafe");
            $privileges->execute(['role' => $role]);
            if (in_array($privileges->fetchColumn(), [true, 1, '1', 't', 'true'], true)) {
                throw new RuntimeException('Reader inherits unsafe PUBLIC privileges; provisioning rolled back.');
            }
            $report['applied'] = true;
            return $report;
        });
    }
}
