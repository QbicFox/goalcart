import AccountTreeIcon from '@mui/icons-material/AccountTree';
import CategoryIcon from '@mui/icons-material/Category';
import Inventory2Icon from '@mui/icons-material/Inventory2';
import LabelIcon from '@mui/icons-material/Label';
import LocalOfferIcon from '@mui/icons-material/LocalOffer';
import PaymentsIcon from '@mui/icons-material/Payments';
import ScaleIcon from '@mui/icons-material/Scale';
import ShoppingCartIcon from '@mui/icons-material/ShoppingCart';
import StyleIcon from '@mui/icons-material/Style';
import WorkspacePremiumIcon from '@mui/icons-material/WorkspacePremium';
import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import type { MissionType } from '../../types';

export interface MissionTypeDefinition {
  value: MissionType;
  label: string;
  description: string;
  icon: ReactNode;
}

/** The seven mission types the engine supports (Phase 4), with builder copy. */
export const MISSION_TYPES: MissionTypeDefinition[] = [
  {
    value: 'amount',
    label: __('Amount', 'faracart'),
    description: __('Reach a cart amount — subtotal, total or discounted subtotal.', 'faracart'),
    icon: <PaymentsIcon />,
  },
  {
    value: 'quantity',
    label: __('Quantity', 'faracart'),
    description: __('Reach a total item quantity in the cart.', 'faracart'),
    icon: <ShoppingCartIcon />,
  },
  {
    value: 'distinct_quantity',
    label: __('Distinct quantity', 'faracart'),
    description: __('Reach a number of distinct products in the cart.', 'faracart'),
    icon: <Inventory2Icon />,
  },
  {
    value: 'category',
    label: __('Category', 'faracart'),
    description: __('Reach an amount or quantity across chosen categories.', 'faracart'),
    icon: <CategoryIcon />,
  },
  {
    value: 'product',
    label: __('Product', 'faracart'),
    description: __('Reach an amount or quantity of specific products.', 'faracart'),
    icon: <LocalOfferIcon />,
  },
  {
    value: 'weight',
    label: __('Weight', 'faracart'),
    description: __('Reach a total cart weight.', 'faracart'),
    icon: <ScaleIcon />,
  },
  {
    value: 'composite',
    label: __('Composite', 'faracart'),
    description: __('Combine child missions with AND/OR logic.', 'faracart'),
    icon: <AccountTreeIcon />,
  },
  {
    value: 'tag',
    label: __('Tag', 'faracart'),
    description: __('Reach an amount or quantity across products with chosen tags.', 'faracart'),
    icon: <LabelIcon />,
  },
  {
    value: 'attribute',
    label: __('Attribute', 'faracart'),
    description: __('Reach an amount or quantity across products with chosen attributes.', 'faracart'),
    icon: <StyleIcon />,
  },
  {
    value: 'brand',
    label: __('Brand', 'faracart'),
    description: __('Reach an amount or quantity of one brand (a product attribute).', 'faracart'),
    icon: <WorkspacePremiumIcon />,
  },
];
