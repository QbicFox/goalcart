import { apiFetch } from './client';
import type {
  SearchAttribute,
  SearchCategory,
  SearchCoupon,
  SearchProduct,
  SearchTag,
  SearchZone,
} from '../types';

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

/** Search products/variations via `GET /faracart/v1/search/products`. */
export async function searchProducts(params: SearchParams = {}): Promise<SearchProduct[]> {
  const data = await apiFetch<SearchList<SearchProduct>>(`/search/products${buildQuery(params)}`);
  return data.items;
}

/** Search product categories via `GET /faracart/v1/search/categories`. */
export async function searchCategories(params: SearchParams = {}): Promise<SearchCategory[]> {
  const data = await apiFetch<SearchList<SearchCategory>>(
    `/search/categories${buildQuery(params)}`
  );
  return data.items;
}

/** Search coupons via `GET /faracart/v1/search/coupons`. */
export async function searchCoupons(params: SearchParams = {}): Promise<SearchCoupon[]> {
  const data = await apiFetch<SearchList<SearchCoupon>>(`/search/coupons${buildQuery(params)}`);
  return data.items;
}

/** Search product tags via `GET /faracart/v1/search/tags`. */
export async function searchTags(params: SearchParams = {}): Promise<SearchTag[]> {
  const data = await apiFetch<SearchList<SearchTag>>(`/search/tags${buildQuery(params)}`);
  return data.items;
}

/**
 * List global attribute taxonomies via `GET /faracart/v1/search/attributes`
 *. `ids` is not applicable — the query uses `q` only.
 */
export async function searchAttributes(params: SearchParams = {}): Promise<SearchAttribute[]> {
  const query = new URLSearchParams();

  if (params.q) {
    query.set('q', params.q);
  }

  const qs = query.toString();
  const data = await apiFetch<SearchList<SearchAttribute>>(
    `/search/attributes${qs ? `?${qs}` : ''}`
  );
  return data.items;
}

/** List shipping zones via `GET /faracart/v1/search/zones`. */
export async function searchZones(params: SearchParams = {}): Promise<SearchZone[]> {
  const data = await apiFetch<SearchList<SearchZone>>(`/search/zones${buildQuery(params)}`);
  return data.items;
}
