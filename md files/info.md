# Server Creation API - Enhanced Workflow Documentation

## Overview

The enhanced server creation API now enforces a structured 6-step workflow that begins with motherboard selection and uses motherboard specifications to determine component limits. This ensures hardware compatibility and provides a guided server building experience.

## Key Changes

### 1. Motherboard-First Approach
- **Required Starting Component**: Server creation now mandates a motherboard UUID
- **Automatic Validation**: Motherboard availability is checked before creating configuration
- **Specification Parsing**: Motherboard JSON specifications determine component limits

### 2. 6-Step Workflow

| Step | Component Type | Status | Description |
|------|---------------|--------|-------------|
| 1 | Motherboard | **Required** | Foundation component - determines all other limits |
| 2 | CPU | **Required** | Limited by socket count from motherboard specs |
| 3 | Memory (RAM) | **Required** | Limited by memory slots from motherboard specs |
| 4 | Storage | **Required** | Limited by SATA/NVMe connections from motherboard |
| 5 | Network/PCIe Cards | Optional | Limited by PCIe expansion slots |
| 6 | Review & Finalization | Final Step | Validate and finalize configuration |

### 3. Progress Tracking

Each API response now includes detailed progress information:

```json
{
  "progress": {
    "total_steps": 6,
    "completed_steps": 2,
    "current_step": 3,
    "current_step_name": "memory_selection",
    "step_descriptions": {
      "1": "Motherboard Selection (Completed)",
      "2": "CPU Selection (Completed)",
      "3": "Memory Selection",
      "4": "Storage Selection",
      "5": "Network/Expansion Cards",
      "6": "Review & Finalization"
    },
    "ready_for_finalization": false,
    "progress_percentage": 33.3
  }
}
```

## API Endpoints

### 1. Server Creation Start
**Endpoint**: `POST /api/api.php`
**Action**: `server-create-start`

**Required Parameters**:
- `server_name`: Name for the server configuration
- `motherboard_uuid`: UUID of the motherboard component (NEW REQUIREMENT)

**Optional Parameters**:
- `description`: Server description
- `category`: Server category (default: "custom")

**Example Request**:
```bash
curl -X POST http://localhost:8000/api/api.php \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -d "action=server-create-start&server_name=Web Server 01&motherboard_uuid=fa410f1c-ab12-46c5-add9-201fcc4985c7"
```

**Example Response**:
```json
{
  "success": 1,
  "authenticated": 1,
  "message": "Server configuration created successfully with motherboard",
  "data": {
    "config_uuid": "12345678-1234-5678-9012-123456789012",
    "server_name": "Web Server 01",
    "motherboard_added": {
      "uuid": "fa410f1c-ab12-46c5-add9-201fcc4985c7",
      "serial_number": "MB345678",
      "specifications": {
        "cpu_sockets": 2,
        "memory_slots": 32,
        "socket_type": "LGA 4189",
        "memory_type": "DDR5"
      }
    },
    "progress": {
      "total_steps": 6,
      "completed_steps": 1,
      "current_step": 2,
      "current_step_name": "cpu_selection"
    },
    "component_limits": {
      "cpu_sockets": 2,
      "memory_slots": 32,
      "storage_slots": {
        "sata_ports": 8,
        "m2_slots": 4,
        "u2_slots": 2
      }
    },
    "next_recommendations": {
      "component_type": "cpu",
      "max_quantity": 2,
      "message": "Add CPU(s) compatible with motherboard socket type"
    }
  }
}
```

### 2. Add Component
**Endpoint**: `POST /api/api.php`
**Action**: `server-add-component`

**Required Parameters**:
- `config_uuid`: Server configuration UUID
- `component_type`: Type of component (cpu, ram, storage, nic, caddy)
- `component_uuid`: UUID of the component to add

**Optional Parameters**:
- `quantity`: Number of components to add (default: 1)
- `slot_position`: Specific slot position
- `notes`: Additional notes
- `override`: Override "in use" status (boolean)

**Component Limits Enforcement**:
- **CPU**: Limited to motherboard socket count
- **RAM**: Limited to motherboard memory slots  
- **Storage**: Limited to combined SATA, M.2, and U.2 connections
- **Network/PCIe**: Warnings for exceeding PCIe slots, but allowed

**Example Request**:
```bash
curl -X POST http://localhost:8000/api/api.php \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -d "action=server-add-component&config_uuid=12345678-1234-5678-9012-123456789012&component_type=cpu&component_uuid=cpu-uuid-here&quantity=2"
```

**Example Response**:
```json
{
  "success": 1,
  "authenticated": 1,
  "message": "Component added successfully",
  "data": {
    "component_added": {
      "type": "cpu",
      "uuid": "cpu-uuid-here",
      "quantity": 2
    },
    "progress": {
      "total_steps": 6,
      "completed_steps": 2,
      "current_step": 3,
      "current_step_name": "memory_selection",
      "progress_percentage": 33.3
    },
    "component_limits": {
      "cpu_sockets": {
        "max": 2,
        "used": 2,
        "remaining": 0
      },
      "memory_slots": {
        "max": 32,
        "used": 0,
        "remaining": 32
      }
    },
    "next_recommendations": [
      {
        "component_type": "ram",
        "priority": "high",
        "message": "Add memory modules - Up to 32 slot(s) available",
        "max_quantity": 32,
        "required": true
      }
    ]
  }
}
```

### 3. Component Limit Validation

When attempting to exceed motherboard limits:

**Error Response Example**:
```json
{
  "success": 0,
  "authenticated": 1,
  "message": "CPU limit exceeded. Motherboard supports 2 CPU socket(s), attempting to add 1 more (current: 2)",
  "data": {
    "current_count": 2,
    "max_allowed": 2,
    "motherboard_specs": {
      "cpu_sockets": 2,
      "socket_type": "LGA 4189"
    }
  }
}
```

## Motherboard Specification Detection

### JSON Data Integration
The system automatically parses motherboard specifications from JSON files in:
`/All JSON/motherboad jsons/motherboard level 3.json`

**Specification Extraction**:
- **CPU Sockets**: From `socket.count` and `socket.type`
- **Memory Slots**: From `memory.slots` and `memory.type`
- **Storage Connections**: Combined from `storage.sata.ports`, `storage.nvme.m2_slots`, `storage.nvme.u2_slots`
- **PCIe Slots**: From `expansion_slots.pcie_slots` array

### Fallback Parsing
If JSON data is not found, the system falls back to parsing the `Notes` field:
- **CPU Sockets**: Regex pattern `(\d+)\s*socket`
- **Memory Slots**: Regex pattern `(\d+)\s*dimm`
- **Memory Type**: Regex pattern `DDR(\d)`

## Progress Calculation Logic

### Step Completion Rules
1. **Step 1 (Motherboard)**: Completed when motherboard is added
2. **Step 2 (CPU)**: Completed when at least one CPU is added
3. **Step 3 (Memory)**: Completed when at least one RAM module is added
4. **Step 4 (Storage)**: Completed when at least one storage device is added
5. **Step 5 (Network)**: Optional - completed when NIC is added
6. **Step 6 (Finalization)**: Available when steps 1-4 are complete

### Finalization Requirements
- ✅ Motherboard (required)
- ✅ CPU (required)
- ✅ RAM (required)  
- ✅ Storage (required)
- ⚪ Network/Expansion (optional)

## Error Handling

### Common Error Scenarios

1. **Missing Motherboard UUID**:
   ```json
   {
     "success": 0,
     "message": "Motherboard UUID is required to start server creation"
   }
   ```

2. **Motherboard Not Found**:
   ```json
   {
     "success": 0,
     "message": "Motherboard not found",
     "data": {
       "motherboard_uuid": "invalid-uuid"
     }
   }
   ```

3. **Motherboard Not Available**:
   ```json
   {
     "success": 0,
     "message": "Motherboard is not available",
     "data": {
       "motherboard_status": 2,
       "status_message": "In Use"
     }
   }
   ```

4. **Component Limit Exceeded**:
   ```json
   {
     "success": 0,
     "message": "RAM limit exceeded. Motherboard supports 32 memory slot(s), attempting to add 1 more (current: 32)"
   }
   ```

## Best Practices

### For API Clients

1. **Always Start with Motherboard**: Use `server-create-start` with a valid motherboard UUID
2. **Check Component Limits**: Use the `component_limits` in responses to validate quantities
3. **Follow Step Order**: While not enforced, following the step order provides better UX
4. **Monitor Progress**: Use `progress` data to show users their completion status
5. **Handle Limit Errors**: Provide meaningful feedback when component limits are exceeded

### For Component Selection

1. **CPU Selection**: 
   - Check socket compatibility with motherboard
   - Respect socket count limits
   - Consider TDP and power requirements

2. **Memory Selection**:
   - Match memory type (DDR4/DDR5) with motherboard
   - Respect slot count limits
   - Consider memory channels for optimal performance

3. **Storage Selection**:
   - Check available connection types (SATA, M.2, U.2)
   - Consider performance requirements
   - Plan for RAID configurations if needed

## Migration Guide

### For Existing Implementations

**Before (Old API)**:
```bash
# Old way - no motherboard required
curl -X POST /api/api.php \
  -d "action=server-create-start&server_name=Server01"
```

**After (New API)**:
```bash
# New way - motherboard required
curl -X POST /api/api.php \
  -d "action=server-create-start&server_name=Server01&motherboard_uuid=uuid-here"
```

### Code Changes Required

1. **Frontend Forms**: Add motherboard selection to server creation forms
2. **Validation Logic**: Update client-side validation to respect component limits
3. **Progress UI**: Implement progress tracking using the new progress data
4. **Error Handling**: Handle new error types for component limits

## Technical Implementation Details

### Database Schema Impact
- No database schema changes required
- Uses existing `server_configurations` and `server_configuration_components` tables
- Leverages existing component inventory tables

### Performance Considerations
- JSON parsing is cached per request
- Component limit calculations are optimized for single queries
- Motherboard specification parsing has fallback mechanisms

### Security
- All existing authentication and authorization remains unchanged
- Component availability checks prevent unauthorized access to failed components
- JWT token requirements are preserved

## Conclusion

The enhanced server creation API provides:
- ✅ **Hardware Compatibility**: Motherboard-driven component limits
- ✅ **Guided Workflow**: Clear 6-step process with progress tracking
- ✅ **Better UX**: Real-time recommendations and limit enforcement  
- ✅ **Flexibility**: Maintains support for all existing component types
- ✅ **Reliability**: Comprehensive error handling and validation

This implementation ensures that users can only create valid server configurations while providing clear guidance throughout the build process.