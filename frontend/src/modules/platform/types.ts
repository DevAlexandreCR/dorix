// Task 5.4 decision: none of these three are platform-only record shapes —
// TenantRecord (org/info), CredentialMetadataRecord (connect/credentials
// read-only view) and WhatsAppLineRecord (used broadly across admin) are
// all still consumed by tenant-scoped admin screens, so they stay owned by
// `modules/admin/types.ts` as the single source of truth and are
// re-exported here for platform's own call sites (this module's `api.ts`
// and views) rather than physically duplicated.
export type { TenantRecord, CredentialMetadataRecord, WhatsAppLineRecord } from '../admin/types';
