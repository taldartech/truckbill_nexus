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
}
