<?php

namespace App\Domains\Master\Catalogue\Enums;

enum OwnerCatalogueType: string
{
    case GoodsType = 'goods_type';
    case TruckType = 'truck_type';
    case TyreLayout = 'tyre_layout';
    case DocumentType = 'document_type';
    case ExpenseCategory = 'expense_category';
    case PackageType = 'package_type';

    public function usesCode(): bool
    {
        return $this === self::DocumentType;
    }

    public function routeSlug(): string
    {
        return match ($this) {
            self::GoodsType => 'goods-types',
            self::TruckType => 'truck-types',
            self::TyreLayout => 'tyre-layouts',
            self::DocumentType => 'document-types',
            self::ExpenseCategory => 'expense-categories',
            self::PackageType => 'package-types',
        };
    }

    public function pageTitle(): string
    {
        return match ($this) {
            self::GoodsType => 'Goods types',
            self::TruckType => 'Truck types',
            self::TyreLayout => 'Tyre layouts',
            self::DocumentType => 'Document types',
            self::ExpenseCategory => 'Expense categories',
            self::PackageType => 'Package types',
        };
    }

    public function pageDescription(): string
    {
        return match ($this) {
            self::GoodsType => 'Standard goods classifications used on lorry receipts and trips.',
            self::TruckType => 'Vehicle body and truck type codes for fleet and LR capture.',
            self::TyreLayout => 'Structured axle and tyre positions for own-fleet vehicles.',
            self::DocumentType => 'KYC and compliance document types for party and fleet records.',
            self::ExpenseCategory => 'Trip and operational expense categories for capture and reporting.',
            self::PackageType => 'Packaging unit types for consignment lines.',
        };
    }

    public static function fromRouteSlug(string $slug): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->routeSlug() === $slug) {
                return $case;
            }
        }

        return null;
    }

    public static function fromRouteName(string $routeName): ?self
    {
        return match ($routeName) {
            'branch.goods-types.index' => self::GoodsType,
            'branch.truck-types.index' => self::TruckType,
            'branch.tyre-layouts.index' => self::TyreLayout,
            'branch.document-types.index' => self::DocumentType,
            'branch.expense-categories.index' => self::ExpenseCategory,
            'branch.package-types.index' => self::PackageType,
            default => null,
        };
    }
}
