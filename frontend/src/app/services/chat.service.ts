import { Injectable } from '@angular/core';
import { HttpClient, HttpHeaders } from '@angular/common/http';
import { Observable } from 'rxjs';
import { AuthService } from './auth.service';

@Injectable({
  providedIn: 'root'
})
export class ChatService {
  private apiUrl = 'http://localhost:8000/v1';
  private fileApiUrl = 'http://localhost:8080/v1'; // API Service direto para arquivos

  constructor(private http: HttpClient, private authService: AuthService) { }

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
}
