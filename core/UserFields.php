<?php

declare(strict_types=1);

namespace Esse;

// Admin-configurable extra fields for registration / profile / user management.
class UserFields
{
    public const TYPES = [
        'text'     => 'Text',
        'textarea' => 'Mehrzeiliger Text',
        'select'   => 'Auswahl',
        'checkbox' => 'Checkbox',
        'date'     => 'Datum',
    ];

    public static function migrateDb(): void
    {
        $p = defined('ESSE_DB_PREFIX') ? \ESSE_DB_PREFIX : 'esse_';
        foreach (Schema::tables($p) as $sql) {
            if (str_contains($sql, '`' . $p . 'user_fields`') || str_contains($sql, '`' . $p . 'user_field_values`')) {
                DB::query($sql);
            }
        }
    }

    // All field definitions, ordered for display
    public static function all(): array
    {
        $t = DB::table('user_fields');
        return DB::fetchAll("SELECT * FROM `{$t}` ORDER BY `sort_order` ASC, `id` ASC");
    }

    public static function forRegister(): array
    {
        return array_values(array_filter(self::all(), fn($f) => (int) $f['show_on_register'] === 1));
    }

    public static function forProfile(): array
    {
        return array_values(array_filter(self::all(), fn($f) => (int) $f['show_on_profile'] === 1));
    }

    // field_key => value, for a given user
    public static function valuesForUser(int $userId): array
    {
        $t  = DB::table('user_fields');
        $tv = DB::table('user_field_values');

        $rows = DB::fetchAll(
            "SELECT f.field_key, v.value
               FROM `{$t}` f
               LEFT JOIN `{$tv}` v ON v.field_id = f.id AND v.user_id = ?",
            [$userId]
        );

        return array_column($rows, 'value', 'field_key');
    }

    // Splits the newline-separated 'options' column into a clean list (for select fields)
    public static function optionList(array $field): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", (string) ($field['options'] ?? '')))));
    }

    /**
     * Reads submitted values for the given fields from $_POST, validates required ones,
     * and returns a [field_key => value] array ready for save(). Appends to $errors on failure.
     */
    public static function collectFromPost(array $fields, array $post, array &$errors): array
    {
        $values = [];

        foreach ($fields as $field) {
            $name = 'cf_' . $field['field_key'];

            if ($field['type'] === 'checkbox') {
                $values[$field['field_key']] = isset($post[$name]) ? '1' : '0';
                continue;
            }

            $value = trim((string) ($post[$name] ?? ''));

            if ($field['type'] === 'select') {
                $options = self::optionList($field);
                if ($value !== '' && !in_array($value, $options, true)) {
                    $value = '';
                }
            }

            if ((int) $field['required'] === 1 && $value === '') {
                $errors[] = htmlspecialchars($field['label']) . ' ist Pflichtfeld.';
            }

            $values[$field['field_key']] = $value;
        }

        return $values;
    }

    // Persists [field_key => value] for the given user (upsert)
    public static function save(int $userId, array $fields, array $values): void
    {
        $tv = DB::table('user_field_values');

        foreach ($fields as $field) {
            $value = $values[$field['field_key']] ?? '';
            DB::query(
                "INSERT INTO `{$tv}` (`user_id`, `field_id`, `value`) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                [$userId, $field['id'], $value]
            );
        }
    }

    // Renders a single field as a Bootstrap form-group, pre-filled with $value
    public static function renderField(array $field, string $value = ''): string
    {
        $key      = htmlspecialchars($field['field_key']);
        $name     = 'cf_' . $key;
        $id       = 'cf-' . $key;
        $label    = htmlspecialchars($field['label']);
        $required = (int) $field['required'] === 1;
        $reqAttr  = $required ? ' required' : '';
        $reqMark  = $required ? ' <span class="text-danger">*</span>' : '';

        $html = '<div class="mb-3">';

        switch ($field['type']) {
            case 'textarea':
                $html .= "<label class=\"form-label\" for=\"{$id}\">{$label}{$reqMark}</label>";
                $html .= "<textarea name=\"{$name}\" id=\"{$id}\" class=\"form-control\" rows=\"3\"{$reqAttr}>"
                       . htmlspecialchars($value) . '</textarea>';
                break;

            case 'select':
                $html .= "<label class=\"form-label\" for=\"{$id}\">{$label}{$reqMark}</label>";
                $html .= "<select name=\"{$name}\" id=\"{$id}\" class=\"form-select\"{$reqAttr}>";
                $html .= '<option value="">— Bitte wählen —</option>';
                foreach (self::optionList($field) as $opt) {
                    $selected = $opt === $value ? ' selected' : '';
                    $html .= '<option value="' . htmlspecialchars($opt) . '"' . $selected . '>'
                           . htmlspecialchars($opt) . '</option>';
                }
                $html .= '</select>';
                break;

            case 'checkbox':
                $checked = $value === '1' ? ' checked' : '';
                $html .= '<div class="form-check">';
                $html .= "<input type=\"checkbox\" name=\"{$name}\" id=\"{$id}\" class=\"form-check-input\" value=\"1\"{$checked}{$reqAttr}>";
                $html .= "<label class=\"form-check-label\" for=\"{$id}\">{$label}{$reqMark}</label>";
                $html .= '</div>';
                break;

            case 'date':
                $html .= "<label class=\"form-label\" for=\"{$id}\">{$label}{$reqMark}</label>";
                $html .= "<input type=\"date\" name=\"{$name}\" id=\"{$id}\" class=\"form-control\" value=\""
                       . htmlspecialchars($value) . "\"{$reqAttr}>";
                break;

            default: // text
                $html .= "<label class=\"form-label\" for=\"{$id}\">{$label}{$reqMark}</label>";
                $html .= "<input type=\"text\" name=\"{$name}\" id=\"{$id}\" class=\"form-control\" value=\""
                       . htmlspecialchars($value) . "\"{$reqAttr}>";
                break;
        }

        $html .= '</div>';
        return $html;
    }
}
