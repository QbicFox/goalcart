import { apiFetch } from './client';
import type { SearchCategory, SearchCoupon, SearchProduct } from '../types';

export interface SearchParams {
  q?: string;
  /** Narrow the result to exactly these ids (preload saved selections). */
  ids?: number[];
  per_page?: number;
}

interface SearchList<T> {
  items: T[];
}

function buildQuery({ q, ids, per_page }: SearchParams): string {
  const query = new URLSearchParams();

  if (q) {
    query.set('q', q);
  }
  if (ids && ids.length > 0) {
    ids.forEach((id) => query.append('ids', String(id)));
  }
  if (per_page) {
    query.set('per_page', String(per_page));
  }

  const qs = query.toString();
  return qs ? `?${qs}` : '';
}

/** Search products/variations via `GET /goalcart/v1/search/products`. */
export async function searchProducts(params: SearchParams = {}): Promise<SearchProduct[]> {
  const data = await apiFetch<SearchList<SearchProduct>>(`/search/products${buildQuery(params)}`);
  return data.items;
}

/** Search product categories via `GET /goalcart/v1/search/categories`. */
export async function searchCategories(params: SearchParams = {}): Promise<SearchCategory[]> {
  const data = await apiFetch<SearchList<SearchCategory>>(
    `/search/categories${buildQuery(params)}`
  );
  return data.items;
}

/** Search coupons via `GET /goalcart/v1/search/coupons`. */
export async function searchCoupons(params: SearchParams = {}): Promise<SearchCoupon[]> {
  const data = await apiFetch<SearchList<SearchCoupon>>(`/search/coupons${buildQuery(params)}`);
  return data.items;
}
