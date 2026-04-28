import { appConfig } from '../../config/app';
import type { HealthResponse, MetaResponse } from '../../types/platform';
import { getJson } from './client';

export function fetchHealth(): Promise<HealthResponse> {
  return getJson<HealthResponse>(appConfig.backendHealthUrl);
}

export function fetchMeta(): Promise<MetaResponse> {
  return getJson<MetaResponse>(appConfig.backendMetaUrl);
}

