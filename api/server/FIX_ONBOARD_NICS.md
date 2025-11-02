# Fix Onboard NICs for Existing Configurations

## Problem
Configurations created before the onboard NIC feature was implemented do not have their motherboard's onboard NICs automatically extracted and tracked. This results in `onboard_nics: 0` in the `server-get-config` response even though the motherboard has onboard NICs in its JSON specifications.

## Solution
Use the new `server-fix-onboard-nics` API endpoint to retroactively extract and add onboard NICs from the motherboard.

## API Endpoint

### `POST /api/api.php`

**Action:** `server-fix-onboard-nics`

**Required Headers:**
```
Authorization: Bearer <JWT_TOKEN>
Content-Type: application/x-www-form-urlencoded
```

**Required Parameters:**
- `action` (string): `server-fix-onboard-nics`
- `config_uuid` (string): The UUID of the server configuration to fix

### Example Request

```bash
curl -X POST https://your-domain.com/bdc_ims/api/api.php \
  -H "Authorization: Bearer $JWT_TOKEN" \
  -d "action=server-fix-onboard-nics" \
  -d "config_uuid=52cf78cd-746d-4599-aa99-490743ad7cff"
```

### Success Response

**Case 1: Onboard NICs were added successfully**

```json
{
  "success": true,
  "authenticated": true,
  "message": "Successfully added onboard NICs from motherboard",
  "timestamp": "2025-10-31 15:00:00",
  "code": 200,
  "data": {
    "config_uuid": "52cf78cd-746d-4599-aa99-490743ad7cff",
    "motherboard_uuid": "8c5f2b87-1e5b-4e8c-a1d2-0b1a5e3f4d6c",
    "onboard_nics_added": 2,
    "nics": [
      {
        "uuid": "onboard-nic-8c5f2b87-1e5b-4e8c-a1d2-0b1a5e3f4d6c-1",
        "source_type": "onboard",
        "parent_motherboard_uuid": "8c5f2b87-1e5b-4e8c-a1d2-0b1a5e3f4d6c",
        "onboard_index": 1,
        "controller": "Intel X710",
        "ports": 2,
        "speed": "10GbE",
        "connector": "SFP+"
      }
    ],
    "message": "2 onboard NIC(s) automatically added"
  }
}
```

**Case 2: Onboard NICs already exist (just updated JSON)**

```json
{
  "success": true,
  "authenticated": true,
  "message": "Onboard NICs already exist, updated configuration",
  "timestamp": "2025-10-31 15:00:00",
  "code": 200,
  "data": {
    "config_uuid": "52cf78cd-746d-4599-aa99-490743ad7cff",
    "existing_onboard_nics": 2,
    "action": "updated_json_only"
  }
}
```

### Error Responses

**No motherboard in configuration:**
```json
{
  "success": false,
  "authenticated": true,
  "message": "No motherboard found in this configuration",
  "code": 404,
  "data": {
    "config_uuid": "52cf78cd-746d-4599-aa99-490743ad7cff",
    "message": "Cannot extract onboard NICs without a motherboard"
  }
}
```

**No onboard NICs in motherboard specs:**
```json
{
  "success": false,
  "authenticated": true,
  "message": "No onboard NICs found in motherboard specifications",
  "code": 404,
  "data": {
    "config_uuid": "52cf78cd-746d-4599-aa99-490743ad7cff",
    "motherboard_uuid": "8c5f2b87-1e5b-4e8c-a1d2-0b1a5e3f4d6c",
    "message": "Motherboard does not have onboard NICs in JSON specifications"
  }
}
```

## What This Endpoint Does

1. **Validates Configuration**: Checks if the configuration exists and user has permissions
2. **Finds Motherboard**: Locates the motherboard component in the configuration
3. **Checks for Existing Onboard NICs**: Determines if onboard NICs were already added
4. **Extracts Onboard NICs**: Reads motherboard JSON specifications and extracts `networking.onboard_nics` array
5. **Creates NIC Entries**: For each onboard NIC:
   - Creates entry in `nicinventory` table with `SourceType = 'onboard'`
   - Creates entry in `server_configuration_components` table
   - Links NIC to motherboard via `ParentComponentUUID`
6. **Updates NIC Config JSON**: Regenerates `server_configurations.nic_config` JSON with proper counts
7. **Returns Summary**: Provides count and details of onboard NICs added

## After Running This Endpoint

Once you've successfully run `server-fix-onboard-nics`, the `server-get-config` response will show:

```json
{
  "nic_configuration": {
    "nics": [
      {
        "uuid": "onboard-nic-8c5f2b87-1e5b-4e8c-a1d2-0b1a5e3f4d6c-1",
        "source_type": "onboard",
        "parent_motherboard_uuid": "8c5f2b87-1e5b-4e8c-a1d2-0b1a5e3f4d6c",
        "onboard_index": 1,
        "status": "in_use",
        "replaceable": true,
        "specifications": {
          "controller": "Intel X710",
          "ports": 2,
          "speed": "10GbE",
          "connector": "SFP+"
        }
      }
    ],
    "summary": {
      "total_nics": 2,
      "onboard_nics": 2,
      "component_nics": 0
    },
    "last_updated": "2025-10-31 15:00:00"
  },
  "nic_summary": {
    "total_nics": 2,
    "onboard_nics": 2,
    "component_nics": 0
  }
}
```

## Permissions Required

User must have one of:
- Own the configuration (be the creator)
- Have `server.edit_all` permission

## Notes

- This endpoint is safe to run multiple times - if onboard NICs already exist, it just updates the JSON
- Onboard NICs are automatically created for new configurations when motherboards are added
- This endpoint is only needed for configurations created before the onboard NIC feature was implemented
- Onboard NICs can be replaced with component NICs using the `server-replace-onboard-nic` endpoint
