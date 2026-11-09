"""Relaciones InnoDB reales, únicamente dentro del entorno desechable del runner."""

import base64
import json
from scenarios import rewrite_package, set_entry


def create(wp):
    wp('eval', '''global $wpdb;
        $p = $wpdb->prefix;
        $definitions = array(
            "ca_templates" => "(id int PRIMARY KEY, code int, UNIQUE KEY pair_key (id,code))",
            "ca_sessions" => "(id int PRIMARY KEY)",
            "ca_history" => "(id int PRIMARY KEY, template_id int, session_id int, FOREIGN KEY(template_id) REFERENCES `{$p}ca_templates`(id) ON DELETE CASCADE ON UPDATE CASCADE, FOREIGN KEY(session_id) REFERENCES `{$p}ca_sessions`(id))",
            "ca_meta" => "(id int PRIMARY KEY, template_id int, code int, FOREIGN KEY(template_id,code) REFERENCES `{$p}ca_templates`(id,code) ON DELETE SET NULL)",
            "ca_self" => "(id int PRIMARY KEY, parent_id int NULL, FOREIGN KEY(parent_id) REFERENCES `{$p}ca_self`(id))",
            "ca_cycle_a" => "(id int PRIMARY KEY, parent_id int NULL)",
            "ca_cycle_b" => "(id int PRIMARY KEY, parent_id int NULL, FOREIGN KEY(parent_id) REFERENCES `{$p}ca_cycle_a`(id))"
        );
        foreach ($definitions as $name => $definition) {
            DCTWPSollers_Migration_Database::query("CREATE TABLE `{$p}{$name}` {$definition} ENGINE=InnoDB");
        }
        foreach (array("ca_templates" => "1,7", "ca_sessions" => "1", "ca_history" => "1,1,1", "ca_meta" => "1,1,7", "ca_self" => "1,1", "ca_cycle_a" => "1,NULL", "ca_cycle_b" => "1,1") as $name => $values) {
            DCTWPSollers_Migration_Database::query("INSERT INTO `{$p}{$name}` VALUES ({$values})");
        }
        DCTWPSollers_Migration_Database::query("ALTER TABLE `{$p}ca_cycle_a` ADD FOREIGN KEY(parent_id) REFERENCES `{$p}ca_cycle_b`(id)");
        DCTWPSollers_Migration_Database::query("UPDATE `{$p}ca_cycle_a` SET parent_id=1");
    ''')


def verify(wp):
    output = wp('eval', '''global $wpdb;
        $p = $wpdb->prefix;
        $keys = $wpdb->get_results($wpdb->prepare("SELECT TABLE_NAME, REFERENCED_TABLE_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND LEFT(TABLE_NAME,%d)=%s", strlen($p . "ca_"), $p . "ca_"));
        if (count($keys) !== 6) { throw new RuntimeException("Missing constraints"); }
        foreach ($keys as $key) {
            if (!str_starts_with($key->REFERENCED_TABLE_NAME, $p . "ca_")) { throw new RuntimeException("Wrong prefix"); }
        }
        if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=%s AND UPDATE_RULE='CASCADE'", $p . "ca_history")) !== 1) { throw new RuntimeException("Update action lost"); }
        foreach (array("ca_self", "ca_cycle_a", "ca_cycle_b") as $name) {
            if ((int) $wpdb->get_var("SELECT parent_id FROM `{$p}{$name}` WHERE id=1") !== 1) { throw new RuntimeException("Cycle data lost"); }
        }
        $wpdb->suppress_errors(true);
        if ($wpdb->query("INSERT INTO `{$p}ca_history` VALUES(99,999,1)") !== false) { throw new RuntimeException("Orphan accepted"); }
        DCTWPSollers_Migration_Database::query("START TRANSACTION");
        try {
            DCTWPSollers_Migration_Database::query("DELETE FROM `{$p}ca_templates` WHERE id=1");
            if ((int) $wpdb->get_var("SELECT COUNT(*) FROM `{$p}ca_history`") !== 0 || $wpdb->get_var("SELECT template_id FROM `{$p}ca_meta` WHERE id=1") !== null) { throw new RuntimeException("Actions lost"); }
        } finally { DCTWPSollers_Migration_Database::query("ROLLBACK"); }
        echo "foreign keys verified";
    ''')
    assert 'foreign keys verified' in output


def failures(wp, archive, fixture, content, prefix):
    excluded = fixture / 'foreign-excluded.zip'
    wp('dctwpsollers', 'export', f'--file={excluded}', f'--exclude-tables={prefix}ca_templates', expected=1)
    assert not excluded.exists()

    def missing_parent(manifest, files):
        entry = manifest['tables']['ca_history']
        lines = files[entry].splitlines()
        header = json.loads(lines[0])
        header['schema'] = header['schema'].replace(prefix + 'ca_templates', prefix + 'missing_parent')
        set_entry(manifest, files, entry, json.dumps(header).encode() + b'\n' + b'\n'.join(lines[1:]) + b'\n')

    bad_parent = fixture / 'foreign-missing-parent.zip'
    rewrite_package(archive, bad_parent, missing_parent)
    wp('dctwpsollers', 'import', f'--file={bad_parent}', '--mode=merge', '--dry-run', expected=1)
    assert not (content.parent / '.maintenance').exists()

    def orphan(manifest, files):
        entry = manifest['tables']['ca_cycle_b']
        lines = files[entry].splitlines()
        row = json.loads(lines[1])
        row[1] = base64.b64encode(b'999').decode()
        set_entry(manifest, files, entry, lines[0] + b'\n' + json.dumps(row).encode() + b'\n')

    bad = fixture / 'foreign-orphan.zip'
    rewrite_package(archive, bad, orphan)
    before = wp('db', 'tables', '--all-tables')
    marker = (content / 'uploads/fixture.txt').read_bytes()
    wp('dctwpsollers', 'import', f'--file={bad}', '--mode=merge', '--dry-run')
    wp('dctwpsollers', 'import', f'--file={bad}', '--mode=merge', '--yes', expected=1)
    assert (content.parent / '.maintenance').exists()
    assert before == wp('db', 'tables', '--all-tables'), 'Preparación con relaciones se limpia tras fallo'
    assert marker == (content / 'uploads/fixture.txt').read_bytes()
    (content.parent / '.maintenance').unlink()
    verify(wp)

    # Una relación de otro prefijo nunca puede quedar apuntando a tablas retiradas.
    outside = 'outside_' + prefix + 'child'
    wp('eval', f'DCTWPSollers_Migration_Database::query("CREATE TABLE `{outside}` (id int, FOREIGN KEY(id) REFERENCES `{prefix}ca_templates`(id)) ENGINE=InnoDB");')
    try:
        for mode in ('merge', 'mirror'):
            wp('dctwpsollers', 'import', f'--file={archive}', f'--mode={mode}', '--dry-run', expected=1)
        wp('dctwpsollers', 'export', f'--file={fixture}/foreign-outside.zip', expected=1)
        assert not (content.parent / '.maintenance').exists()
    finally:
        wp('eval', f'DCTWPSollers_Migration_Database::query("DROP TABLE `{outside}`");')

    wp('eval', '''global $wpdb; $p = $wpdb->prefix;
        DCTWPSollers_Migration_Database::query("CREATE TABLE `{$p}extra_fk_a` (id int PRIMARY KEY, parent_id int) ENGINE=InnoDB");
        DCTWPSollers_Migration_Database::query("CREATE TABLE `{$p}extra_fk_b` (id int PRIMARY KEY, parent_id int, FOREIGN KEY(parent_id) REFERENCES `{$p}extra_fk_a`(id)) ENGINE=InnoDB");
        DCTWPSollers_Migration_Database::query("ALTER TABLE `{$p}extra_fk_a` ADD FOREIGN KEY(parent_id) REFERENCES `{$p}extra_fk_b`(id)");
    ''')
    wp('dctwpsollers', 'import', f'--file={archive}', '--mode=merge', '--dry-run')
    wp('dctwpsollers', 'import', f'--file={archive}', '--mode=mirror', '--yes')
    assert prefix + 'extra_fk_a' not in wp('db', 'tables', '--all-tables-with-prefix')
    assert prefix + 'extra_fk_b' not in wp('db', 'tables', '--all-tables-with-prefix')
    verify(wp)
    print('OK: claves compuestas, autorreferencias, ciclos, CASCADE/SET NULL, exclusiones, otros prefijos y rechazo de huérfanos antes de copiar archivos.')
