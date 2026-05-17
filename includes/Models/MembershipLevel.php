<?php

namespace BuyMeCoffee\Models;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class MembershipLevel extends Model
{
    protected $table = 'buymecoffee_membership_levels';

    public function find($id)
    {
        return $this->decodeJsonFields(parent::find($id));
    }

    public function getActive()
    {
        $rows = $this->getQuery()
            ->where('status', 'active')
            ->orderBy('sort_order', 'ASC')
            ->get();

        return array_map([$this, 'decodeJsonFields'], $rows ?: []);
    }

    public function getForAdmin()
    {
        $rows = $this->getQuery()
            ->where('status', '!=', 'deleted')
            ->orderBy('sort_order', 'ASC')
            ->get();

        return array_map([$this, 'decodeJsonFields'], $rows ?: []);
    }

    public function findForAdmin($id)
    {
        $row = $this->getQuery()
            ->where('id', absint($id))
            ->where('status', '!=', 'deleted')
            ->first();

        return $this->decodeJsonFields($row);
    }

    public function updateData($id, $data)
    {
        return $this->getQuery()->where('id', $id)->update($data);
    }

    public function reorder($ids)
    {
        foreach ($ids as $sortOrder => $id) {
            $this->getQuery()->where('id', absint($id))->update(['sort_order' => absint($sortOrder)]);
        }
    }

    public function decodeJsonFields($row)
    {
        if (!$row) {
            return $row;
        }
        if (is_object($row)) {
            $row->rewards      = $this->decodeJsonValue($row->rewards);
            $row->access_rules = $this->decodeJsonValue($row->access_rules);
            $row->payment_type = !empty($row->payment_type) ? sanitize_key($row->payment_type) : 'subscription';
        }
        return $row;
    }

    private function decodeJsonValue($value)
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
