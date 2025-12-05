import { Injectable, OnDestroy } from '@angular/core';
import { HttpClient, HttpHeaders } from '@angular/common/http';
import { Observable, Subject, BehaviorSubject } from 'rxjs';
import { takeUntil, filter } from 'rxjs/operators';
import { AuthService } from './auth.service';
import { WebsocketService, StatusUpdate } from './websocket.service';
import { EnvironmentService } from './environment.service';

export interface Message {
  message_id: string;
  conversation_id: string;
  from_user_id: string;
  content: string;
  status?: 'PENDING' | 'SENT' | 'DELIVERED' | 'READ' | 'FAILED';
  created_at: string;
  file_id?: string;
  file?: {
    filename: string;
    file_size: number;
    content_type: string;
    download_url: string;
  };
}

@Injectable({
  providedIn: 'root'
})
export class ChatService implements OnDestroy {
  private apiUrl: string;
  private fileApiUrl: string;

  private destroy$ = new Subject<void>();

  // Cache de status de mensagens para atualização em tempo real
  private messageStatuses = new Map<string, BehaviorSubject<string>>();

  constructor(
    private http: HttpClient,
    private authService: AuthService,
    private websocketService: WebsocketService,
    private env: EnvironmentService
  ) {
    // Use centralized URLs from EnvironmentService
    this.apiUrl = this.env.apiGatewayUrl;
    this.fileApiUrl = this.env.apiServiceUrl;

    console.log('[ChatService] Using API URL:', this.apiUrl);
    console.log('[ChatService] Using File API URL:', this.fileApiUrl);

    // Escutar atualizações de status do WebSocket
    this.websocketService.getStatusUpdates().pipe(
      takeUntil(this.destroy$)
    ).subscribe((update: StatusUpdate) => {
      this.handleStatusUpdate(update);
    });
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  private getHeaders() {
    const user = this.authService.currentUserValue;
    return new HttpHeaders({
      'Authorization': `Bearer ${user.token}`,
      'Content-Type': 'application/json'
    });
  }

  getConversations(): Observable<any> {
    return this.http.get(`${this.apiUrl}/conversations`, { headers: this.getHeaders() });
  }

  getMessages(conversationId: string): Observable<any> {
    return this.http.get(`${this.apiUrl}/conversations/${conversationId}/messages`, { headers: this.getHeaders() });
  }

  sendMessage(conversationId: string, content: string, messageType: string = 'text', fileId?: string): Observable<any> {
    const body: any = {
      conversation_id: conversationId,
      content: content,
      message_type: messageType
    };

    if (fileId) {
      body.file_id = fileId;
    }

    return this.http.post(`${this.apiUrl}/messages`, body, { headers: this.getHeaders() });
  }

  // File upload methods
  initiateFileUpload(conversationId: string, filename: string, fileSize: number, contentType: string): Observable<any> {
    return this.http.post(`${this.fileApiUrl}/files/upload/initiate`, {
      conversation_id: conversationId,
      filename: filename,
      file_size: fileSize,
      content_type: contentType
    }, { headers: this.getHeaders() });
  }

  uploadFilePart(uploadId: string, fileId: string, partNumber: number, data: Blob): Observable<any> {
    const formData = new FormData();
    formData.append('upload_id', uploadId);
    formData.append('file_id', fileId);
    formData.append('part_number', partNumber.toString());
    formData.append('data', data);

    const user = this.authService.currentUserValue;
    const headers = new HttpHeaders({
      'Authorization': `Bearer ${user.token}`
      // Don't set Content-Type, browser will set it automatically with boundary
    });

    return this.http.post(`${this.fileApiUrl}/files/upload/part`, formData, { headers });
  }

  completeFileUpload(uploadId: string, fileId: string): Observable<any> {
    return this.http.post(`${this.fileApiUrl}/files/upload/complete`, {
      upload_id: uploadId,
      file_id: fileId
    }, { headers: this.getHeaders() });
  }

  abortFileUpload(uploadId: string, fileId: string): Observable<any> {
    return this.http.post(`${this.fileApiUrl}/files/upload/abort`, {
      upload_id: uploadId,
      file_id: fileId
    }, { headers: this.getHeaders() });
  }

  getFileInfo(fileId: string): Observable<any> {
    return this.http.get(`${this.fileApiUrl}/files/${fileId}`, { headers: this.getHeaders() });
  }

  getFileDownloadUrl(fileId: string): Observable<any> {
    // Usar download direto via backend (não abre nova aba, faz download direto)
    const url = `${this.fileApiUrl}/files/${fileId}/download`;
    window.location.href = url; // Força download através do backend
    return new Observable(observer => {
      observer.next({ success: true });
      observer.complete();
    });
  }

  downloadFile(fileId: string): Observable<Blob> {
    return this.http.get(`${this.fileApiUrl}/files/${fileId}/download`, {
      headers: this.getHeaders(),
      responseType: 'blob'
    });
  }

  getConversationFiles(conversationId: string, limit: number = 20, offset: number = 0): Observable<any> {
    return this.http.get(`${this.fileApiUrl}/conversations/${conversationId}/files?limit=${limit}&offset=${offset}`, { headers: this.getHeaders() });
  }

  createPrivateConversation(otherUserId: string): Observable<any> {
    return this.http.post(`${this.apiUrl}/conversations/private`, {
      other_user_id: otherUserId
    }, { headers: this.getHeaders() });
  }

  createGroupConversation(groupName: string, memberUserIds: string[]): Observable<any> {
    return this.http.post(`${this.apiUrl}/conversations/group`, {
      group_name: groupName,
      member_user_ids: memberUserIds
    }, { headers: this.getHeaders() });
  }

  markConversationAsRead(conversationId: string): Observable<any> {
    return this.http.post(`${this.apiUrl}/conversations/${conversationId}/read`, {}, { headers: this.getHeaders() });
  }

  getUnreadCount(conversationId: string): Observable<any> {
    return this.http.get(`${this.apiUrl}/conversations/${conversationId}/unread`, { headers: this.getHeaders() });
  }

  // ==========================================
  // WebSocket Status Updates Integration
  // ==========================================

  /**
   * Handle incoming status update from WebSocket
   */
  private handleStatusUpdate(update: StatusUpdate): void {
    const statusSubject = this.messageStatuses.get(update.message_id);
    if (statusSubject) {
      statusSubject.next(update.status);
    }
    console.log(`[ChatService] Status update: ${update.message_id} -> ${update.status}`);
  }

  /**
   * Subscribe to status updates for a specific message
   */
  getMessageStatusObservable(messageId: string): Observable<string> {
    if (!this.messageStatuses.has(messageId)) {
      this.messageStatuses.set(messageId, new BehaviorSubject<string>('PENDING'));
    }
    return this.messageStatuses.get(messageId)!.asObservable();
  }

  /**
   * Initialize message status tracking
   */
  initializeMessageStatus(messageId: string, initialStatus: string = 'PENDING'): void {
    if (!this.messageStatuses.has(messageId)) {
      this.messageStatuses.set(messageId, new BehaviorSubject<string>(initialStatus));
    } else {
      this.messageStatuses.get(messageId)!.next(initialStatus);
    }
  }

  /**
   * Get current status for a message
   */
  getMessageStatus(messageId: string): string {
    const subject = this.messageStatuses.get(messageId);
    return subject ? subject.getValue() : 'PENDING';
  }

  /**
   * Subscribe to WebSocket updates for a conversation
   */
  subscribeToConversation(conversationId: string): void {
    this.websocketService.subscribeToConversation(conversationId);
  }

  /**
   * Unsubscribe from WebSocket updates for a conversation
   */
  unsubscribeFromConversation(conversationId: string): void {
    this.websocketService.unsubscribeFromConversation(conversationId);
  }

  /**
   * Get WebSocket connection state
   */
  getConnectionState(): Observable<string> {
    return this.websocketService.getConnectionState();
  }

  /**
   * Check if WebSocket is connected
   */
  isWebSocketConnected(): boolean {
    return this.websocketService.isConnected();
  }

  /**
   * Listen for new message events via WebSocket
   * Returns an Observable that emits when a new message is received
   */
  onNewMessage(): Observable<any> {
    return this.websocketService.getMessages().pipe(
      filter((msg: any) => msg.type === 'status_update' && msg.data?.event === 'new_message')
    );
  }
}
