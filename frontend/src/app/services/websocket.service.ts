import { Injectable, OnDestroy } from '@angular/core';
import { BehaviorSubject, Observable, Subject, timer } from 'rxjs';
import { takeUntil, filter } from 'rxjs/operators';
import { AuthService } from './auth.service';

export interface StatusUpdate {
  type: 'status_update';
  message_id: string;
  conversation_id: string;
  status: 'SENT' | 'DELIVERED' | 'READ' | 'FAILED';
  connector: string;
  timestamp: string;
}

export interface WebSocketMessage {
  type: string;
  [key: string]: any;
}

export type ConnectionState = 'connecting' | 'connected' | 'disconnected' | 'error';

@Injectable({
  providedIn: 'root'
})
export class WebsocketService implements OnDestroy {
  private wsUrl = 'ws://localhost:8081';
  private socket: WebSocket | null = null;
  private destroy$ = new Subject<void>();
  private reconnectAttempts = 0;
  private maxReconnectAttempts = 5;
  private reconnectDelay = 1000;
  
  // Subjects for different event types
  private connectionState$ = new BehaviorSubject<ConnectionState>('disconnected');
  private statusUpdates$ = new Subject<StatusUpdate>();
  private messages$ = new Subject<WebSocketMessage>();
  private errors$ = new Subject<Error>();

  // Subscribed conversations
  private subscribedConversations = new Set<string>();

  constructor(private authService: AuthService) {
    // Auto-connect when user is authenticated
    this.authService.currentUser.pipe(
      takeUntil(this.destroy$),
      filter((user: any) => !!user && !!user.token)
    ).subscribe(() => {
      this.connect();
    });
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
    this.disconnect();
  }

  /**
   * Connects to WebSocket server
   */
  connect(): void {
    if (this.socket?.readyState === WebSocket.OPEN) {
      console.log('[WebSocket] Already connected');
      return;
    }

    const user = this.authService.currentUserValue;
    if (!user?.token) {
      console.warn('[WebSocket] No auth token available');
      return;
    }

    this.connectionState$.next('connecting');
    console.log('[WebSocket] Connecting to', this.wsUrl);

    try {
      // Connect with auth token as query param
      this.socket = new WebSocket(`${this.wsUrl}?token=${user.token}`);
      this.setupSocketHandlers();
    } catch (error) {
      console.error('[WebSocket] Connection error:', error);
      this.connectionState$.next('error');
      this.scheduleReconnect();
    }
  }

  /**
   * Setup WebSocket event handlers
   */
  private setupSocketHandlers(): void {
    if (!this.socket) return;

    this.socket.onopen = () => {
      console.log('[WebSocket] ✅ Connected');
      this.connectionState$.next('connected');
      this.reconnectAttempts = 0;
      
      // Re-subscribe to all conversations
      this.subscribedConversations.forEach(convId => {
        this.subscribeToConversation(convId);
      });
    };

    this.socket.onmessage = (event) => {
      try {
        const data: WebSocketMessage = JSON.parse(event.data);
        console.log('[WebSocket] 📥 Message received:', data.type);
        
        // Emit to general messages stream
        this.messages$.next(data);
        
        // Handle specific message types
        if (data.type === 'status_update') {
          this.statusUpdates$.next(data as StatusUpdate);
        }
      } catch (error) {
        console.error('[WebSocket] Error parsing message:', error);
      }
    };

    this.socket.onerror = (event) => {
      console.error('[WebSocket] ❌ Error:', event);
      this.errors$.next(new Error('WebSocket error'));
      this.connectionState$.next('error');
    };

    this.socket.onclose = (event) => {
      console.log('[WebSocket] 🔌 Disconnected:', event.code, event.reason);
      this.connectionState$.next('disconnected');
      
      // Reconnect if not intentionally closed
      if (event.code !== 1000) {
        this.scheduleReconnect();
      }
    };
  }

  /**
   * Disconnect from WebSocket server
   */
  disconnect(): void {
    if (this.socket) {
      this.socket.close(1000, 'User disconnected');
      this.socket = null;
    }
    this.subscribedConversations.clear();
    this.connectionState$.next('disconnected');
  }

  /**
   * Schedule reconnection with exponential backoff
   */
  private scheduleReconnect(): void {
    if (this.reconnectAttempts >= this.maxReconnectAttempts) {
      console.error('[WebSocket] Max reconnect attempts reached');
      return;
    }

    const delay = this.reconnectDelay * Math.pow(2, this.reconnectAttempts);
    this.reconnectAttempts++;
    
    console.log(`[WebSocket] Reconnecting in ${delay}ms (attempt ${this.reconnectAttempts})`);
    
    timer(delay).pipe(
      takeUntil(this.destroy$)
    ).subscribe(() => {
      this.connect();
    });
  }

  /**
   * Subscribe to status updates for a conversation
   */
  subscribeToConversation(conversationId: string): void {
    this.subscribedConversations.add(conversationId);
    
    if (this.socket?.readyState === WebSocket.OPEN) {
      this.send({
        action: 'subscribe',
        conversation_id: conversationId
      });
      console.log('[WebSocket] Subscribed to conversation:', conversationId);
    }
  }

  /**
   * Unsubscribe from a conversation
   */
  unsubscribeFromConversation(conversationId: string): void {
    this.subscribedConversations.delete(conversationId);
    
    if (this.socket?.readyState === WebSocket.OPEN) {
      this.send({
        action: 'unsubscribe',
        conversation_id: conversationId
      });
      console.log('[WebSocket] Unsubscribed from conversation:', conversationId);
    }
  }

  /**
   * Send message to WebSocket server
   */
  private send(data: object): void {
    if (this.socket?.readyState === WebSocket.OPEN) {
      this.socket.send(JSON.stringify(data));
    } else {
      console.warn('[WebSocket] Cannot send, not connected');
    }
  }

  /**
   * Get connection state as Observable
   */
  getConnectionState(): Observable<ConnectionState> {
    return this.connectionState$.asObservable();
  }

  /**
   * Get status updates for all subscribed conversations
   */
  getStatusUpdates(): Observable<StatusUpdate> {
    return this.statusUpdates$.asObservable();
  }

  /**
   * Get status updates for a specific message
   */
  getMessageStatus(messageId: string): Observable<StatusUpdate> {
    return this.statusUpdates$.pipe(
      filter((update: StatusUpdate) => update.message_id === messageId)
    );
  }

  /**
   * Get status updates for a specific conversation
   */
  getConversationStatusUpdates(conversationId: string): Observable<StatusUpdate> {
    return this.statusUpdates$.pipe(
      filter((update: StatusUpdate) => update.conversation_id === conversationId)
    );
  }

  /**
   * Get all WebSocket messages
   */
  getMessages(): Observable<WebSocketMessage> {
    return this.messages$.asObservable();
  }

  /**
   * Get WebSocket errors
   */
  getErrors(): Observable<Error> {
    return this.errors$.asObservable();
  }

  /**
   * Check if currently connected
   */
  isConnected(): boolean {
    return this.socket?.readyState === WebSocket.OPEN;
  }

  /**
   * Get current connection state value
   */
  getCurrentState(): ConnectionState {
    return this.connectionState$.getValue();
  }
}
