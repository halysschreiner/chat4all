import { Component, OnInit } from '@angular/core';
import { HttpClient, HttpHeaders } from '@angular/common/http';

/**
 * Componente principal do Chat4All
 * Interface simples para demonstração das funcionalidades
 */
@Component({
  selector: 'app-root',
  templateUrl: './app.component.html',
  styleUrls: ['./app.component.css']
})
export class AppComponent implements OnInit {
  // API Base URL
  private apiUrl = `http://${window.location.hostname}:8080/api`;

  // Estado da aplicação
  currentView: 'login' | 'register' | 'chat' = 'login';
  isAuthenticated = false;
  currentUser: any = null;
  authToken: string = '';

  // Dados do formulário
  loginEmail = '';
  loginPassword = '';
  registerUsername = '';
  registerEmail = '';
  registerPassword = '';

  // Chat
  conversations: any[] = [];
  selectedConversation: any = null;
  messages: any[] = [];
  newMessage = '';

  // Criar conversa/grupo
  showCreateConversation = false;
  otherUserId = ''; // Para conversa privada
  groupName = '';
  groupMembers = ''; // IDs separados por vírgula

  constructor(private http: HttpClient) { }

  ngOnInit() {
    // Verificar se tem token salvo
    const savedToken = localStorage.getItem('auth_token');
    const savedUser = localStorage.getItem('current_user');

    if (savedToken && savedUser) {
      this.authToken = savedToken;
      this.currentUser = JSON.parse(savedUser);
      this.isAuthenticated = true;
      this.currentView = 'chat';
      this.loadConversations();
    }
  }

  /**
   * Realizar login
   */
  async login() {
    try {
      const response: any = await this.http.post(`${this.apiUrl}/auth/login`, {
        email: this.loginEmail,
        password: this.loginPassword
      }).toPromise();

      if (response.success) {
        this.authToken = response.token;
        this.currentUser = response.user;
        this.isAuthenticated = true;
        this.currentView = 'chat';

        // Salvar no localStorage
        localStorage.setItem('auth_token', this.authToken);
        localStorage.setItem('current_user', JSON.stringify(this.currentUser));

        // Carregar conversas
        this.loadConversations();

        alert('Login realizado com sucesso!');
      } else {
        alert('Erro no login: ' + response.message);
      }
    } catch (error: any) {
      alert('Erro ao fazer login: ' + error.message);
    }
  }

  /**
   * Registrar novo usuário
   */
  async register() {
    try {
      const response: any = await this.http.post(`${this.apiUrl}/auth/register`, {
        username: this.registerUsername,
        email: this.registerEmail,
        password: this.registerPassword
      }).toPromise();

      if (response.success) {
        alert('Usuário registrado! Faça login agora.');
        this.currentView = 'login';
        this.loginEmail = this.registerEmail;
      } else {
        alert('Erro no registro: ' + response.message);
      }
    } catch (error: any) {
      alert('Erro ao registrar: ' + error.message);
    }
  }

  /**
   * Logout
   */
  logout() {
    this.isAuthenticated = false;
    this.authToken = '';
    this.currentUser = null;
    this.currentView = 'login';
    localStorage.removeItem('auth_token');
    localStorage.removeItem('current_user');
  }

  /**
   * Carregar conversas do usuário
   */
  async loadConversations() {
    try {
      const headers = new HttpHeaders({
        'Authorization': `Bearer ${this.authToken}`
      });

      const response: any = await this.http.get(`${this.apiUrl}/conversations`, { headers }).toPromise();

      if (response.success) {
        this.conversations = response.conversations;
      }
    } catch (error) {
      console.error('Erro ao carregar conversas:', error);
    }
  }

  /**
   * Selecionar conversa e carregar mensagens
   */
  async selectConversation(conversation: any) {
    this.selectedConversation = conversation;
    await this.loadMessages();

    // Auto-refresh a cada 3 segundos
    setInterval(() => {
      if (this.selectedConversation) {
        this.loadMessages();
      }
    }, 3000);
  }

  /**
   * Carregar mensagens da conversa selecionada
   */
  async loadMessages() {
    if (!this.selectedConversation) return;

    try {
      const headers = new HttpHeaders({
        'Authorization': `Bearer ${this.authToken}`
      });

      const response: any = await this.http.get(
        `${this.apiUrl}/conversations/${this.selectedConversation.conversation_id}/messages`,
        { headers }
      ).toPromise();

      if (response.success) {
        this.messages = response.messages.reverse(); // Ordem crescente
      }
    } catch (error) {
      console.error('Erro ao carregar mensagens:', error);
    }
  }

  /**
   * Enviar mensagem
   */
  async sendMessage() {
    if (!this.newMessage.trim() || !this.selectedConversation) return;

    try {
      const headers = new HttpHeaders({
        'Authorization': `Bearer ${this.authToken}`
      });

      const response: any = await this.http.post(
        `${this.apiUrl}/messages/send`,
        {
          conversation_id: this.selectedConversation.conversation_id,
          message_type: 'text',
          content: this.newMessage
        },
        { headers }
      ).toPromise();

      if (response.success) {
        this.newMessage = '';
        await this.loadMessages();
      }
    } catch (error) {
      console.error('Erro ao enviar mensagem:', error);
    }
  }

  /**
   * Marcar mensagem como lida
   */
  async markAsRead(messageId: string) {
    try {
      const headers = new HttpHeaders({
        'Authorization': `Bearer ${this.authToken}`
      });

      await this.http.post(
        `${this.apiUrl}/messages/read`,
        { message_id: messageId },
        { headers }
      ).toPromise();

      await this.loadMessages();
    } catch (error) {
      console.error('Erro ao marcar como lida:', error);
    }
  }

  /**
   * Criar conversa privada
   */
  async createPrivateConversation() {
    try {
      const headers = new HttpHeaders({
        'Authorization': `Bearer ${this.authToken}`
      });

      const response: any = await this.http.post(
        `${this.apiUrl}/conversations/private`,
        { other_user_id: this.otherUserId },
        { headers }
      ).toPromise();

      if (response.success) {
        alert('Conversa criada!');
        this.showCreateConversation = false;
        this.otherUserId = '';
        await this.loadConversations();
      }
    } catch (error: any) {
      alert('Erro ao criar conversa: ' + error.message);
    }
  }

  /**
   * Criar grupo
   */
  async createGroup() {
    try {
      const headers = new HttpHeaders({
        'Authorization': `Bearer ${this.authToken}`
      });

      const memberIds = this.groupMembers.split(',').map(id => id.trim());

      const response: any = await this.http.post(
        `${this.apiUrl}/conversations/group`,
        {
          group_name: this.groupName,
          member_user_ids: memberIds
        },
        { headers }
      ).toPromise();

      if (response.success) {
        alert('Grupo criado!');
        this.showCreateConversation = false;
        this.groupName = '';
        this.groupMembers = '';
        await this.loadConversations();
      }
    } catch (error: any) {
      alert('Erro ao criar grupo: ' + error.message);
    }
  }

  /**
   * Obter badge de status da mensagem
   */
  getStatusBadge(status: string): string {
    const badges: any = {
      'sent': '📤 Enviada',
      'delivered': '✓ Entregue',
      'read': '✓✓ Lida'
    };
    return badges[status] || status;
  }

  /**
   * Obter cor do status
   */
  getStatusColor(status: string): string {
    const colors: any = {
      'sent': '#999',
      'delivered': '#0084ff',
      'read': '#0084ff'
    };
    return colors[status] || '#999';
  }

  getReadByNames(readBy: any[]): string {
    if (!readBy || readBy.length === 0) {
      return '';
    }
    return readBy.map(r => r.username).join(', ');
  }
}