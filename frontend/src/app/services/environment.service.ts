import { Injectable } from '@angular/core';

/**
 * Environment Service
 * 
 * Centralizes all backend URLs based on the server hostname.
 * This ensures that when users access the app via LAN IP,
 * all API calls go to the correct server.
 */
@Injectable({
	providedIn: 'root'
})
export class EnvironmentService {

	/**
	 * The hostname where the frontend was loaded from.
	 * This will be the server's IP when accessed via LAN.
	 */
	private readonly hostname = window.location.hostname;

	/**
	 * API Gateway URL (Slim Framework REST API)
	 * Port 8000 - Handles most API calls via gRPC proxy
	 */
	get apiGatewayUrl(): string {
		return `http://${this.hostname}:8000/v1`;
	}

	/**
	 * API Service URL (Direct HTTP access)
	 * Port 8080 - Used for file uploads and direct access
	 */
	get apiServiceUrl(): string {
		return `http://${this.hostname}:8080/v1`;
	}

	/**
	 * Legacy API URL for app.component.ts
	 * Port 8080 - Uses /api prefix
	 */
	get legacyApiUrl(): string {
		return `http://${this.hostname}:8080/api`;
	}

	/**
	 * WebSocket URL for real-time updates
	 * Port 3333 - WebSocket worker
	 * Uses 127.0.0.1 for localhost to bypass WSL relay interception
	 */
	get websocketUrl(): string {
		const wsHost = this.hostname === 'localhost' ? '127.0.0.1' : this.hostname;
		return `ws://${wsHost}:3333`;
	}

	/**
	 * Get the current hostname for debugging
	 */
	get currentHostname(): string {
		return this.hostname;
	}
}
