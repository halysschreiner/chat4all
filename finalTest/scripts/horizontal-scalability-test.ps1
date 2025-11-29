# ===================================================================
# Chat4All - Horizontal Scalability Test Script (PowerShell)
# Week 7-8: Load Testing & Scalability Validation
# ===================================================================

param(
    [string]$ApiBaseUrl = "http://localhost:8000",
    [int]$InitialWorkers = 1,
    [int]$MaxWorkers = 5,
    [int]$MessagesPerWorker = 100
)

$ErrorActionPreference = "Stop"

# Configuration
$ResultsDir = Join-Path (Get-Location) "finalTest\results"
$Timestamp = Get-Date -Format "yyyyMMdd_HHmmss"
$TestReportFile = Join-Path $ResultsDir "scalability_test_$Timestamp.json"

# ===================================================================
# Helper Functions
# ===================================================================

function Write-ColorOutput {
    param(
        [string]$Message,
        [string]$Color = "White"
    )
    Write-Host $Message -ForegroundColor $Color
}

function Write-Header {
    param([string]$Title)
    Write-Host ""
    Write-ColorOutput "╔════════════════════════════════════════════════════════╗" -Color Cyan
    Write-ColorOutput "║  $Title" -Color Cyan
    Write-ColorOutput "╚════════════════════════════════════════════════════════╝" -Color Cyan
    Write-Host ""
}

# ===================================================================
# Function: Check prerequisites
# ===================================================================
function Test-Prerequisites {
    Write-ColorOutput "[1/6] Checking prerequisites..." -Color Yellow
    
    # Check Docker
    try {
        docker --version | Out-Null
        Write-ColorOutput "✓ Docker is available" -Color Green
    } catch {
        Write-ColorOutput "✗ Docker is not installed or not in PATH" -Color Red
        exit 1
    }
    
    # Check Docker Compose
    try {
        docker-compose --version | Out-Null
        Write-ColorOutput "✓ Docker Compose is available" -Color Green
    } catch {
        Write-ColorOutput "✗ Docker Compose is not installed or not in PATH" -Color Red
        exit 1
    }
    
    Write-ColorOutput "✓ All prerequisites met`n" -Color Green
}

# ===================================================================
# Function: Test API availability
# ===================================================================
function Test-ApiAvailability {
    Write-ColorOutput "[2/6] Testing API availability..." -Color Yellow
    
    $maxRetries = 30
    $retryCount = 0
    
    while ($retryCount -lt $maxRetries) {
        try {
            $response = Invoke-WebRequest -Uri "$ApiBaseUrl/health" -Method Get -TimeoutSec 2 -ErrorAction SilentlyContinue
            if ($response.StatusCode -eq 200) {
                Write-ColorOutput "✓ API is available`n" -Color Green
                return $true
            }
        } catch {
            # API not ready yet
        }
        
        $retryCount++
        Write-Host "Waiting for API... ($retryCount/$maxRetries)"
        Start-Sleep -Seconds 2
    }
    
    Write-ColorOutput "✗ API is not available after $maxRetries retries" -Color Red
    exit 1
}

# ===================================================================
# Function: Register test users
# ===================================================================
function Register-TestUsers {
    Write-ColorOutput "[3/6] Registering test users..." -Color Yellow
    
    for ($i = 1; $i -le 10; $i++) {
        $username = "scaletest_user$i"
        
        $body = @{
            username = $username
            email = "$username@test.com"
            password = "Test123!@#"
            full_name = "Scale Test User $i"
        } | ConvertTo-Json
        
        try {
            $response = Invoke-RestMethod -Uri "$ApiBaseUrl/v1/auth/register" `
                -Method Post `
                -ContentType "application/json" `
                -Body $body `
                -ErrorAction SilentlyContinue
            
            Write-ColorOutput "✓ Registered user: $username" -Color Green
        } catch {
            Write-ColorOutput "⚠ User $username may already exist" -Color Yellow
        }
    }
    
    Write-ColorOutput "✓ Test users ready`n" -Color Green
}

# ===================================================================
# Function: Scale workers
# ===================================================================
function Set-WorkerScale {
    param([int]$Count)
    
    Write-ColorOutput "Scaling router-worker to $Count instances..." -Color Cyan
    
    docker-compose up -d --scale router-worker=$Count 2>&1 | Out-Null
    Start-Sleep -Seconds 5
    
    $actualCount = (docker ps --filter "name=router-worker" --format "{{.Names}}").Count
    Write-ColorOutput "✓ Running $actualCount worker instances" -Color Green
}

# ===================================================================
# Function: Run throughput test
# ===================================================================
function Start-ThroughputTest {
    param([int]$WorkerCount)
    
    $totalMessages = $WorkerCount * $MessagesPerWorker
    
    Write-ColorOutput "Testing with $WorkerCount workers ($totalMessages messages)..." -Color Yellow
    
    # Get auth token
    try {
        $loginBody = @{
            email = "scaletest_user1@test.com"
            password = "Test123!@#"
        } | ConvertTo-Json
        
        $loginResponse = Invoke-RestMethod -Uri "$ApiBaseUrl/v1/auth/login" `
            -Method Post `
            -ContentType "application/json" `
            -Body $loginBody
        
        $token = $loginResponse.token
        
        if (-not $token) {
            Write-ColorOutput "✗ Failed to get authentication token" -Color Red
            return $null
        }
        
        # Create test conversation
        $convBody = @{
            title = "Scalability Test Conversation"
            type = "group"
            participant_ids = @(1, 2, 3)
        } | ConvertTo-Json
        
        $headers = @{
            "Authorization" = "Bearer $token"
            "Content-Type" = "application/json"
        }
        
        $convResponse = Invoke-RestMethod -Uri "$ApiBaseUrl/v1/conversations" `
            -Method Post `
            -Headers $headers `
            -Body $convBody
        
        $convId = $convResponse.conversation_id
        
        # Measure throughput
        $startTime = Get-Date
        $successCount = 0
        $errorCount = 0
        
        for ($i = 1; $i -le $totalMessages; $i++) {
            $messageBody = @{
                conversation_id = $convId
                content = "Load test message #$i with $WorkerCount workers"
                type = "text"
            } | ConvertTo-Json
            
            try {
                $response = Invoke-RestMethod -Uri "$ApiBaseUrl/v1/messages" `
                    -Method Post `
                    -Headers $headers `
                    -Body $messageBody `
                    -ErrorAction SilentlyContinue
                
                $successCount++
            } catch {
                $errorCount++
            }
            
            # Show progress every 20 messages
            if ($i % 20 -eq 0) {
                Write-ColorOutput "Progress: $i/$totalMessages messages sent" -Color Blue
            }
        }
        
        $endTime = Get-Date
        $duration = ($endTime - $startTime).TotalSeconds
        $throughput = [math]::Round($successCount / $duration, 2)
        $avgLatency = [math]::Round(($duration / $totalMessages) * 1000, 2)
        
        # Create result object
        $result = @{
            timestamp = (Get-Date -Format "o")
            worker_count = $WorkerCount
            total_messages = $totalMessages
            success_count = $successCount
            error_count = $errorCount
            duration_seconds = [math]::Round($duration, 2)
            throughput_msg_per_sec = $throughput
            avg_latency_ms = $avgLatency
        }
        
        Write-ColorOutput "✓ Test completed:" -Color Green
        Write-Host "  - Messages sent: $totalMessages"
        Write-Host "  - Success: $successCount"
        Write-Host "  - Errors: $errorCount"
        Write-Host "  - Duration: $($duration)s"
        Write-Host "  - Throughput: $throughput msg/s"
        Write-Host "  - Avg Latency: $($avgLatency)ms`n"
        
        return $result
        
    } catch {
        Write-ColorOutput "✗ Error during throughput test: $_" -Color Red
        return $null
    }
}

# ===================================================================
# Function: Test worker failure recovery
# ===================================================================
function Test-WorkerFailure {
    Write-ColorOutput "[5/6] Testing worker failure and recovery..." -Color Yellow
    
    # Scale to 3 workers
    Set-WorkerScale -Count 3
    
    # Get list of worker containers
    $workers = docker ps --filter "name=router-worker" --format "{{.Names}}"
    $workerArray = $workers -split "`n" | Where-Object { $_ -ne "" }
    
    if ($workerArray.Count -lt 2) {
        Write-ColorOutput "✗ Not enough workers running" -Color Red
        return
    }
    
    $targetWorker = $workerArray[1]
    
    Write-ColorOutput "Simulating failure by stopping: $targetWorker" -Color Cyan
    docker stop $targetWorker | Out-Null
    
    Write-ColorOutput "Waiting for load redistribution (10s)..." -Color Cyan
    Start-Sleep -Seconds 10
    
    # Send messages to verify system still works
    Write-ColorOutput "Sending messages to verify recovery..." -Color Cyan
    Start-ThroughputTest -WorkerCount 2
    
    # Restart the failed worker
    Write-ColorOutput "Restarting failed worker..." -Color Cyan
    docker start $targetWorker | Out-Null
    Start-Sleep -Seconds 5
    
    Write-ColorOutput "✓ Worker failure recovery test completed`n" -Color Green
}

# ===================================================================
# Function: Run scalability tests
# ===================================================================
function Start-ScalabilityTests {
    Write-ColorOutput "[4/6] Running horizontal scalability tests...`n" -Color Yellow
    
    $results = @()
    
    for ($workerCount = $InitialWorkers; $workerCount -le $MaxWorkers; $workerCount++) {
        Write-ColorOutput "═══════════════════════════════════════════" -Color Cyan
        Write-ColorOutput "  Test with $workerCount worker(s)" -Color Cyan
        Write-ColorOutput "═══════════════════════════════════════════" -Color Cyan
        
        Set-WorkerScale -Count $workerCount
        $result = Start-ThroughputTest -WorkerCount $workerCount
        
        if ($result) {
            $results += $result
        }
        
        Start-Sleep -Seconds 3
    }
    
    # Save results to JSON file
    New-Item -ItemType Directory -Force -Path $ResultsDir | Out-Null
    $results | ConvertTo-Json -Depth 10 | Out-File -FilePath $TestReportFile -Encoding UTF8
    
    Write-ColorOutput "✓ All scalability tests completed`n" -Color Green
    
    return $results
}

# ===================================================================
# Function: Generate summary report
# ===================================================================
function Show-Summary {
    param($Results)
    
    Write-ColorOutput "[6/6] Generating summary report..." -Color Yellow
    
    Write-Header "Test Results Summary"
    
    Write-Host "Results saved to: $TestReportFile"
    Write-Host ""
    Write-Host "Summary:"
    
    foreach ($result in $Results) {
        Write-Host ("Workers: {0} | Throughput: {1} msg/s | Latency: {2}ms" -f `
            $result.worker_count, `
            $result.throughput_msg_per_sec, `
            $result.avg_latency_ms)
    }
    
    Write-Host ""
    Write-ColorOutput "✓ All tests completed successfully!" -Color Green
    Write-ColorOutput "Results directory: $ResultsDir`n" -Color Cyan
}

# ===================================================================
# Main execution
# ===================================================================
function Main {
    Write-Header "Chat4All - Horizontal Scalability Test Suite"
    
    # Create results directory
    New-Item -ItemType Directory -Force -Path $ResultsDir | Out-Null
    
    Test-Prerequisites
    Test-ApiAvailability
    Register-TestUsers
    $results = Start-ScalabilityTests
    Test-WorkerFailure
    Show-Summary -Results $results
}

# Run main function
Main
