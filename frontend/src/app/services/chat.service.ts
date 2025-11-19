import { Injectable } from '@angular/core';
import { HttpClient, HttpHeaders } from '@angular/common/http';
import { Observable } from 'rxjs';
import { AuthService } from './auth.service';

@Injectable({
  providedIn: 'root'
})
export class ChatService {
  private apiUrl = 'http://localhost:8000/api';

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

  sendMessage(conversationId: string, content: string): Observable<any> {
    return this.http.post(`${this.apiUrl}/messages/send`, {
      conversation_id: conversationId,
      content: content,
      message_type: 'text'
    }, { headers: this.getHeaders() });
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
