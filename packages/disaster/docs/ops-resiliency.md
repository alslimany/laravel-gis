# Disaster plugin — operations resiliency (pointer)

High-availability, offline field kits, and multi-region failover for emergency operations are **out of scope** for the in-repo MVP stubs.

For production deployment patterns (RTO/RPO, queue fallbacks, CAP gateway redundancy), follow **Annex B** in the Lumina Disaster & EM product guide and your organization’s continuity plan.

This plugin’s console and ingest paths assume a reachable Laravel app and database; plan accordingly for disconnected exercises.
