import AccountTreeIcon from '@mui/icons-material/AccountTree';
import CategoryIcon from '@mui/icons-material/Category';
import Inventory2Icon from '@mui/icons-material/Inventory2';
import LocalOfferIcon from '@mui/icons-material/LocalOffer';
import PaymentsIcon from '@mui/icons-material/Payments';
import ScaleIcon from '@mui/icons-material/Scale';
import ShoppingCartIcon from '@mui/icons-material/ShoppingCart';
import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import type { GoalType } from '../../types';

export interface GoalTypeDefinition {
  value: GoalType;
  label: string;
  description: string;
  icon: ReactNode;
}

/** The seven goal types the engine supports (Phase 4), with builder copy. */
export const GOAL_TYPES: GoalTypeDefinition[] = [
  {
    value: 'amount',
    label: __('Amount', 'goalcart'),
    description: __('Reach a cart amount — subtotal, total or discounted subtotal.', 'goalcart'),
    icon: <PaymentsIcon />,
  },
  {
    value: 'quantity',
    label: __('Quantity', 'goalcart'),
    description: __('Reach a total item quantity in the cart.', 'goalcart'),
    icon: <ShoppingCartIcon />,
  },
  {
    value: 'distinct_quantity',
    label: __('Distinct quantity', 'goalcart'),
    description: __('Reach a number of distinct products in the cart.', 'goalcart'),
    icon: <Inventory2Icon />,
  },
  {
    value: 'category',
    label: __('Category', 'goalcart'),
    description: __('Reach an amount or quantity across chosen categories.', 'goalcart'),
    icon: <CategoryIcon />,
  },
  {
    value: 'product',
    label: __('Product', 'goalcart'),
    description: __('Reach an amount or quantity of specific products.', 'goalcart'),
    icon: <LocalOfferIcon />,
  },
  {
    value: 'weight',
    label: __('Weight', 'goalcart'),
    description: __('Reach a total cart weight.', 'goalcart'),
    icon: <ScaleIcon />,
  },
  {
    value: 'composite',
    label: __('Composite', 'goalcart'),
    description: __('Combine child goals with AND/OR logic.', 'goalcart'),
    icon: <AccountTreeIcon />,
  },
];
